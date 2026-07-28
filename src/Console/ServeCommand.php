<?php

namespace Eyika\Atom\Octane\Console;

use Eyika\Atom\Framework\Foundation\Console\Command;
use Eyika\Atom\Octane\ServerFactory;
use Throwable;

/**
 * Boot the application once and serve it with a persistent worker on the configured
 * runtime (native / swoole / roadrunner / frankenphp):
 *
 *   php artisan octane:serve
 *   php artisan octane:serve --server=swoole --host=0.0.0.0 --port=8080 --workers=8
 *   php artisan octane:serve --max-requests=1000
 *
 * For RoadRunner point .rr.yaml's server command at this (`--server=roadrunner`); for
 * FrankenPHP run this from a worker script (`--server=frankenphp`).
 */
class ServeCommand extends Command
{
    public string $signature = 'octane:serve';
    public string $description = 'Serve the application with a persistent Octane-style worker';

    public function handle(): bool
    {
        $overrides = array_filter([
            'server'       => $this->option('server'),
            'host'         => $this->option('host'),
            'port'         => $this->option('port'),
            'workers'      => $this->option('workers'),
            'max_requests' => $this->option('max-requests'),
        ], fn ($v) => $v !== null);

        $overrides['onLog'] = fn (string $msg) => $this->info($msg);

        try {
            $server = ServerFactory::make(app(), $overrides);
            $this->info("Booting application once — serving with the [{$server->name()}] runtime…");
            $server->start();
        } catch (Throwable $e) {
            $this->error('octane:serve failed: ' . $e->getMessage());
            return false;
        }

        return true;
    }
}
