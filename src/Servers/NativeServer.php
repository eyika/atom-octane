<?php

namespace Eyika\Atom\Octane\Servers;

use Eyika\Atom\Octane\Contracts\Server;
use Eyika\Atom\Octane\Http\HttpMessage;
use Eyika\Atom\Octane\Worker;
use RuntimeException;
use Throwable;

/**
 * Dependency-free production server on PHP's own stream_socket_server. It forks a pool of
 * worker processes (POSIX/pcntl) that each boot the app once and serve connections with
 * HTTP keep-alive; workers recycle after their request/memory quota, requests are bounded
 * by a timeout, and the master reforks recycled workers and supports graceful reload
 * (SIGHUP) and shutdown (SIGTERM/SIGINT).
 *
 * Where pcntl is unavailable (e.g. Windows) it degrades to a single blocking process —
 * correct, but without concurrency; use a real runtime (Swoole/RoadRunner/FrankenPHP) for
 * production load on those platforms.
 */
class NativeServer implements Server
{
    protected Worker $worker;
    /** @var array<string,mixed> */
    protected array $options;
    /** @var callable|null */
    protected $onLog;
    protected bool $recycleAfterRequest = false;

    public function __construct(Worker $worker, array $options = [])
    {
        $this->worker = $worker;
        $this->options = $options + [
            'host' => '127.0.0.1', 'port' => 8090, 'workers' => 1,
            'request_timeout' => 30, 'keep_alive' => true, 'keep_alive_timeout' => 5,
            'max_request_size' => 10 * 1024 * 1024,
        ];
        $this->onLog = $options['onLog'] ?? null;
    }

    public function name(): string
    {
        return 'native';
    }

    public function start(): void
    {
        $host = $this->options['host'];
        $port = (int) $this->options['port'];

        $ctx = stream_context_create(['socket' => ['so_reuseport' => true, 'backlog' => 511]]);
        $socket = @stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $ctx
        );
        if ($socket === false) {
            throw new RuntimeException("Cannot bind {$host}:{$port} — {$errstr} ({$errno})");
        }

        $workers = max(1, (int) $this->options['workers']);
        $canFork = $workers > 1 && function_exists('pcntl_fork');

        $this->log(sprintf(
            'Atom Octane (native) listening on http://%s:%d — %s',
            $host,
            $port,
            $canFork ? "{$workers} workers" : 'single process'
        ));

        $canFork ? $this->supervise($socket, $workers) : $this->acceptLoop($socket);
    }

    // --- Master: fork the pool, refork recycled workers, handle reload/stop -----------

    protected function supervise($socket, int $workers): void
    {
        pcntl_async_signals(true);
        $children = [];

        $spawn = function () use ($socket, &$children): void {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $this->acceptLoop($socket);
                exit(0);
            }
            if ($pid > 0) {
                $children[$pid] = true;
            }
        };

        for ($i = 0; $i < $workers; $i++) {
            $spawn();
        }

        $stop = false;
        $reload = false;
        pcntl_signal(SIGTERM, function () use (&$stop) { $stop = true; });
        pcntl_signal(SIGINT, function () use (&$stop) { $stop = true; });
        pcntl_signal(SIGHUP, function () use (&$reload) { $reload = true; });

        while (!$stop) {
            if ($reload) {
                $this->log('reloading workers…');
                foreach (array_keys($children) as $cpid) {
                    @posix_kill($cpid, SIGTERM);
                }
                $reload = false;
            }

            // Reap any exited (recycled/reloaded) child and refork to hold the pool size.
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
            if ($pid > 0) {
                unset($children[$pid]);
                if (!$stop) {
                    $spawn();
                }
            }

            usleep(100000);
        }

        $this->log('shutting down…');
        foreach (array_keys($children) as $cpid) {
            @posix_kill($cpid, SIGTERM);
        }
        foreach (array_keys($children) as $cpid) {
            pcntl_waitpid($cpid, $status);
        }
        @fclose($socket);
        $this->log('stopped');
    }

    // --- Worker: boot once, then accept + serve until told to stop or recycle ----------

    protected function acceptLoop($socket): void
    {
        $this->worker->boot();

        $running = true;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
            pcntl_signal(SIGINT, function () use (&$running) { $running = false; });
        }

        while ($running) {
            $conn = @stream_socket_accept($socket, 1); // 1s timeout so signals are honoured
            if ($conn === false) {
                continue;
            }
            $this->serveConnection($conn);
            @fclose($conn);

            if ($this->worker->shouldRecycle()) {
                $this->log('worker recycling after ' . $this->worker->requestsHandled() . ' requests');
                return; // exit → master reforks a fresh worker
            }
        }
    }

    /** Serve one client connection, handling multiple keep-alive requests over it. */
    protected function serveConnection($conn): void
    {
        stream_set_timeout($conn, (int) $this->options['keep_alive_timeout']);

        do {
            $raw = HttpMessage::read($conn, (int) $this->options['max_request_size']);
            if ($raw === '' || strpos($raw, "\r\n\r\n") === false) {
                return; // idle timeout / closed / malformed
            }

            $parsed = HttpMessage::parse($raw);
            $this->recycleAfterRequest = false;

            $response = $this->handleWithTimeout($parsed['source']);

            $keepAlive = $this->options['keep_alive']
                && $parsed['keep_alive']
                && !$this->recycleAfterRequest;

            @fwrite($conn, HttpMessage::build($response, $keepAlive, $parsed['version']));

            if (!$keepAlive || $this->worker->shouldRecycle()) {
                return;
            }
        } while (true);
    }

    /**
     * Run one request under a wall-clock timeout (SIGALRM, async). A timed-out or crashed
     * request returns a 5xx and forces the worker to recycle, since its state may be dirty.
     */
    protected function handleWithTimeout(array $source): array
    {
        $timeout = (int) $this->options['request_timeout'];
        $armed = $timeout > 0 && function_exists('pcntl_alarm');

        if ($armed) {
            pcntl_signal(SIGALRM, function () {
                throw new RuntimeException('request exceeded the configured timeout');
            });
            pcntl_alarm($timeout);
        }

        try {
            return $this->worker->handle($source);
        } catch (Throwable $e) {
            $this->recycleAfterRequest = true;
            $status = str_contains($e->getMessage(), 'timeout') ? 504 : 500;
            return ['status' => $status, 'headers' => [], 'body' => HttpMessage::REASONS[$status] ?? 'Error'];
        } finally {
            if ($armed) {
                pcntl_alarm(0);
            }
        }
    }

    protected function log(string $message): void
    {
        if ($this->onLog !== null) {
            ($this->onLog)($message);
        }
    }
}
