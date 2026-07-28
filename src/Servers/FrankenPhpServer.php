<?php

namespace Eyika\Atom\Octane\Servers;

use Eyika\Atom\Octane\Contracts\Server;
use Eyika\Atom\Octane\Worker;
use RuntimeException;

/**
 * Serves the Worker in FrankenPHP worker mode. FrankenPHP (a Caddy/Go server that embeds
 * PHP) manages the process pool, HTTP/2+3, automatic HTTPS, and reload; each request calls
 * our handler with the superglobals populated, and we run it against the booted app.
 *
 * Requires the FrankenPHP binary running this script as a worker (frankenphp_handle_request
 * must exist). See the README for the Caddyfile `worker` directive.
 */
class FrankenPhpServer implements Server
{
    /** @param array<string,mixed> $options */
    public function __construct(protected Worker $worker, protected array $options = [])
    {
    }

    public function name(): string
    {
        return 'frankenphp';
    }

    public function start(): void
    {
        if (!function_exists('frankenphp_handle_request')) {
            throw new RuntimeException(
                'Not running under FrankenPHP worker mode (frankenphp_handle_request is unavailable). '
                . 'Launch the FrankenPHP binary with a worker pointing at this script.'
            );
        }

        $this->worker->boot();

        $handler = function (): void {
            $this->emit($this->worker->handle($this->toSource()));
        };

        // frankenphp_handle_request() blocks for the next request, invokes $handler, and
        // returns true until FrankenPHP asks the worker to stop.
        while (\frankenphp_handle_request($handler)) {
            if ($this->worker->shouldRecycle()) {
                break; // exit → FrankenPHP starts a fresh worker
            }
            gc_collect_cycles();
        }
    }

    /** Build a Worker source array from the per-request superglobals FrankenPHP populates. */
    protected function toSource(): array
    {
        return [
            'server'  => $_SERVER,
            'query'   => $_GET,
            'post'    => $_POST,
            'cookies' => $_COOKIE,
            'files'   => $_FILES,
            'headers' => function_exists('getallheaders') ? (getallheaders() ?: []) : [],
            'rawBody' => file_get_contents('php://input') ?: '',
        ];
    }

    /** Emit the captured response natively (FrankenPHP forwards it to the client). */
    protected function emit(array $result): void
    {
        http_response_code($result['status'] ?? 200);
        foreach ($result['headers'] ?? [] as $line) {
            header($line, false); // false: preserve multiple headers (e.g. Set-Cookie)
        }
        echo $result['body'] ?? '';
    }
}
