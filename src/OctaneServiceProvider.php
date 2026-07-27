<?php

namespace Eyika\Atom\Octane;

use Eyika\Atom\Framework\Foundation\ServiceProvider;
use Eyika\Atom\Octane\Console\ServeCommand;

/**
 * Auto-discovered via composer.json extra.atom.providers (PKG-02): installing this
 * package into an Atom app immediately exposes the `octane:serve` command — no manual
 * provider registration. This is the proof that the framework's package-discovery works.
 */
class OctaneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The worker is resolvable from the container if an app wants to embed it.
        $this->app->bind(Worker::class, fn ($app) => new Worker($app));
    }

    public function boot(): void
    {
        $this->commands([
            ServeCommand::class,
        ]);
    }
}
