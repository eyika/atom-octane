<?php

namespace Eyika\Atom\Octane;

use Eyika\Atom\Framework\Foundation\ServiceProvider;
use Eyika\Atom\Octane\Console\ServeCommand;

/**
 * Auto-discovered via composer.json extra.atom.providers (PKG-02): installing this
 * package exposes the `octane:serve` command and merges config/octane.php (publishable
 * with `php artisan vendor:publish --tag=octane-config`).
 */
class OctaneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/octane.php', 'octane');

        $this->app->bind(Worker::class, function ($app) {
            return new Worker(
                $app,
                (int) config('octane.max_requests', 500),
                (int) config('octane.max_memory', 0)
            );
        });
    }

    public function boot(): void
    {
        $this->commands([
            ServeCommand::class,
        ]);

        $this->publishes([
            __DIR__ . '/../config/octane.php' => base_path('config/octane.php'),
        ], 'octane-config');
    }
}
