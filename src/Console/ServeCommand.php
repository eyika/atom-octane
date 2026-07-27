<?php

namespace Eyika\Atom\Octane\Console;

use Eyika\Atom\Framework\Foundation\Console\Command;
use Eyika\Atom\Octane\HttpServer;
use Eyika\Atom\Octane\Worker;
use Throwable;

/**
 * Boots the application once and serves it over a persistent HTTP worker:
 *
 *   php console octane:serve --host=127.0.0.1 --port=8090
 */
class ServeCommand extends Command
{
    public string $signature = 'octane:serve';
    public string $description = 'Serve the application with a persistent Octane-style worker';

    public function handle(): bool
    {
        $host = (string) ($this->option('host') ?: '127.0.0.1');
        $port = (int) ($this->option('port') ?: 8090);

        $worker = new Worker(app());
        $server = new HttpServer($worker, fn (string $msg) => $this->info($msg));

        $this->info("Booting application (once) …");

        try {
            $server->serve($host, $port);
        } catch (Throwable $e) {
            $this->error('octane:serve failed: ' . $e->getMessage());
            return false;
        }

        return true;
    }
}
