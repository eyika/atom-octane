<?php

namespace Eyika\Atom\Octane\Servers;

use Eyika\Atom\Octane\Contracts\Server;
use Eyika\Atom\Octane\Worker;
use RuntimeException;

/**
 * Serves the Worker under Swoole's HTTP server. Coroutines are disabled: the framework's
 * per-request static state is not coroutine-safe, so each Swoole worker processes one
 * request at a time (concurrency comes from `worker_num`). Swoole's own `max_request`
 * recycles a worker after the configured number of requests.
 *
 * Requires ext-swoole (or ext-openswoole). Run directly: `octane:serve --server=swoole`.
 */
class SwooleServer implements Server
{
    /** @param array<string,mixed> $options */
    public function __construct(protected Worker $worker, protected array $options = [])
    {
    }

    public function name(): string
    {
        return 'swoole';
    }

    public function start(): void
    {
        $class = class_exists(\Swoole\Http\Server::class)
            ? \Swoole\Http\Server::class
            : (class_exists(\OpenSwoole\Http\Server::class) ? \OpenSwoole\Http\Server::class : null);

        if ($class === null) {
            throw new RuntimeException('ext-swoole / ext-openswoole is not installed. Try: pecl install swoole');
        }

        $host = (string) ($this->options['host'] ?? '127.0.0.1');
        $port = (int) ($this->options['port'] ?? 8090);

        /** @var \Swoole\Http\Server $server */
        $server = new $class($host, $port);
        $server->set([
            'worker_num'       => max(1, (int) ($this->options['workers'] ?? 1)),
            'max_request'      => (int) ($this->options['max_requests'] ?? 500), // Swoole-side recycling
            'enable_coroutine' => false,
            'max_wait_time'    => (int) ($this->options['request_timeout'] ?? 30),
        ]);

        $server->on('workerStart', function () {
            $this->worker->boot();
        });

        $server->on('request', function ($request, $response) use ($server) {
            $result = $this->worker->handle($this->toSource($request));
            $this->emit($response, $result);

            // Memory-based recycling (Swoole handles request-count recycling itself).
            if ($this->worker->shouldRecycle() && method_exists($server, 'stop')) {
                $server->stop($server->getWorkerId());
            }
        });

        $this->log("Atom Octane (swoole) listening on http://{$host}:{$port}");
        $server->start();
    }

    /** Build a Worker source array from a Swoole request. */
    protected function toSource($request): array
    {
        $server = array_change_key_case((array) ($request->server ?? []), CASE_UPPER);
        $headers = (array) ($request->header ?? []);
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return [
            'server'  => $server,
            'query'   => (array) ($request->get ?? []),
            'post'    => (array) ($request->post ?? []),
            'cookies' => (array) ($request->cookie ?? []),
            'files'   => (array) ($request->files ?? []),
            'headers' => $headers,
            'rawBody' => $request->rawContent() ?: '',
        ];
    }

    /** Emit the captured response through the Swoole response. */
    protected function emit($response, array $result): void
    {
        $response->status($result['status'] ?? 200);
        foreach ($result['headers'] ?? [] as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $response->header(trim($name), trim($value));
        }
        $response->end($result['body'] ?? '');
    }

    protected function log(string $message): void
    {
        if (($this->options['onLog'] ?? null) !== null) {
            ($this->options['onLog'])($message);
        }
    }
}
