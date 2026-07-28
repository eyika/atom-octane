<?php

namespace Eyika\Atom\Octane;

use Eyika\Atom\Framework\Foundation\Application;
use Eyika\Atom\Octane\Contracts\Server;
use Eyika\Atom\Octane\Servers\FrankenPhpServer;
use Eyika\Atom\Octane\Servers\NativeServer;
use Eyika\Atom\Octane\Servers\RoadRunnerServer;
use Eyika\Atom\Octane\Servers\SwooleServer;
use InvalidArgumentException;

/**
 * Builds the configured Octane server (and the Worker it drives) from config/octane.php,
 * with per-call overrides (e.g. from `octane:serve` flags).
 */
class ServerFactory
{
    /** @param array<string,mixed> $overrides */
    public static function make(Application $app, array $overrides = []): Server
    {
        $get = function (string $key, $default) use ($overrides) {
            if (array_key_exists($key, $overrides) && $overrides[$key] !== null) {
                return $overrides[$key];
            }
            return function_exists('config') ? config("octane.$key", $default) : $default;
        };

        $worker = new Worker($app, (int) $get('max_requests', 500), (int) $get('max_memory', 0));

        $options = [
            'host'               => (string) $get('host', '127.0.0.1'),
            'port'               => (int) $get('port', 8090),
            'workers'            => self::resolveWorkers($get('workers', 'auto')),
            'request_timeout'    => (int) $get('request_timeout', 30),
            'keep_alive'         => (bool) $get('keep_alive', true),
            'keep_alive_timeout' => (int) $get('keep_alive_timeout', 5),
            'max_request_size'   => (int) $get('max_request_size', 10 * 1024 * 1024),
            'onLog'              => $overrides['onLog'] ?? null,
        ];

        $server = strtolower((string) $get('server', 'native'));

        return match ($server) {
            'native'                          => new NativeServer($worker, $options),
            'swoole', 'openswoole'            => new SwooleServer($worker, $options),
            'roadrunner', 'road-runner', 'rr' => new RoadRunnerServer($worker, $options),
            'frankenphp', 'franken'           => new FrankenPhpServer($worker, $options),
            default => throw new InvalidArgumentException("Unknown octane server [{$server}]. Use native|swoole|roadrunner|frankenphp."),
        };
    }

    /** Resolve the worker count ("auto" → CPU cores). */
    public static function resolveWorkers(mixed $workers): int
    {
        if (is_int($workers) || (is_string($workers) && ctype_digit($workers))) {
            return max(1, (int) $workers);
        }
        return max(1, self::cpuCount());
    }

    public static function cpuCount(): int
    {
        // Windows: read the env var (avoids spawning a shell that can't find nproc).
        if (str_contains(strtolower(PHP_OS), 'win')) {
            return max(1, (int) (getenv('NUMBER_OF_PROCESSORS') ?: 1));
        }

        // POSIX: nproc / sysctl where available.
        if (function_exists('shell_exec')) {
            $count = @shell_exec('nproc 2>/dev/null') ?: @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if ($count !== null && (int) $count > 0) {
                return (int) $count;
            }
        }

        return 1;
    }
}
