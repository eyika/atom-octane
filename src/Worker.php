<?php

namespace Eyika\Atom\Octane;

use Eyika\Atom\Framework\Exceptions\Http\BaseHttpException;
use Eyika\Atom\Framework\Foundation\Application;
use Eyika\Atom\Framework\Foundation\Contracts\ExceptionHandler;
use Eyika\Atom\Framework\Foundation\Contracts\Kernel;
use Eyika\Atom\Framework\Http\BaseResponse;
use Eyika\Atom\Framework\Http\JsonResponse;
use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Http\Response;
use Eyika\Atom\Framework\Http\Route;
use Eyika\Atom\Framework\Http\Server;
use Eyika\Atom\Framework\Support\Facade\Facade;
use Throwable;

/**
 * The heart of the Octane-style runtime: boot the application ONCE, then serve many
 * requests against the already-booted kernel. This is where all of the framework's
 * worker-safety work (the WRK cluster) pays off:
 *
 *   - boot()   registers facades + providers + the route table a single time.
 *   - handle() builds a Request from an injected source (WRK-01), dispatches it with
 *              response output captured instead of echoed (WRK-02), reads the captured
 *              status/headers/body, then calls Application::flushRequestState() (WRK-03..09)
 *              so nothing from this request leaks into the next.
 *
 * handle() is transport-agnostic: HttpServer feeds it sockets, but a test can feed it a
 * plain source array and assert on the returned response — no network required.
 */
class Worker
{
    /** Fallback snapshot key used when the app declares no route maps. */
    protected const DEFAULT_SNAPSHOT = "\0default";

    protected Application $app;

    /**
     * Route table snapshot PER map (keyed by map name). Each map's route file is loaded
     * in isolation at boot so that maps sharing a path (e.g. both web.php and api.php
     * define "/") don't overwrite each other in one merged table — mirroring how
     * Server::handle loads only the resolved map's file per request.
     *
     * @var array<string, array<string,mixed>>
     */
    protected array $routeSnapshots = [];
    protected bool $booted = false;

    /** Requests served since boot, and the recycling thresholds (0 = unlimited). */
    protected int $requests = 0;
    protected int $maxRequests;
    protected int $maxMemoryBytes;

    public function __construct(Application $app, int $maxRequests = 500, int $maxMemoryMb = 0)
    {
        $this->app = $app;
        $this->maxRequests = max(0, $maxRequests);
        $this->maxMemoryBytes = max(0, $maxMemoryMb) * 1024 * 1024;
    }

    public function app(): Application
    {
        return $this->app;
    }

    /** How many requests this worker has served since boot. */
    public function requestsHandled(): int
    {
        return $this->requests;
    }

    /**
     * Whether the worker should be recycled (the process restarted) — it has served its
     * request quota or crossed the memory ceiling. Long-lived PHP workers accumulate
     * memory (fragmentation, caches, leaks in app/third-party code); recycling bounds it.
     */
    public function shouldRecycle(): bool
    {
        if ($this->maxRequests > 0 && $this->requests >= $this->maxRequests) {
            return true;
        }
        if ($this->maxMemoryBytes > 0 && memory_get_usage(true) >= $this->maxMemoryBytes) {
            return true;
        }
        return false;
    }

    /**
     * Boot the application a single time: wire facades, register+boot providers (which
     * declares the RouteServiceProvider's maps), and load every map's route file once.
     * require_once runs each route file exactly once, so we snapshot the resulting table
     * and restore it per request rather than re-requiring a file that won't re-execute.
     */
    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }

        new Server($this->app);           // registers response/request/session facades
        $this->app->registerProviders();  // boots providers → RouteServiceProvider maps

        $mapsWithFiles = array_filter(Route::maps(), fn ($map) => $map->getFile() !== null);

        if ($mapsWithFiles) {
            // Load each map's route file in isolation and snapshot it separately, so shared
            // paths across maps don't overwrite each other in one merged table.
            foreach ($mapsWithFiles as $map) {
                Route::clearRegistered();
                Route::loadRoutesFile($map->getName(), $map->getFile());
                $this->routeSnapshots[$map->getName()] = Route::getRoutes();
            }
            Route::clearRegistered();
        } else {
            // No map-driven route files (e.g. routes registered directly) — snapshot the
            // whole table and restore it for every request under the fallback key.
            $this->routeSnapshots[self::DEFAULT_SNAPSHOT] = Route::getRoutes();
        }

        BaseResponse::captureOutput(true); // capture responses instead of emitting them
        $this->booted = true;

        return $this;
    }

    /**
     * Handle one request built from an injected source (see Request::__construct for the
     * accepted keys: server/query/post/cookies/files/headers/rawBody). Returns the
     * captured response as ['status' => int, 'headers' => string[], 'body' => string].
     */
    public function handle(array $source): array
    {
        $this->boot();

        BaseResponse::resetCapture();

        // Fresh response objects per request: they're shared mutable singletons, so a
        // prior request's status/headers/body would otherwise bleed into this one.
        $this->app->instance('response', new Response());
        $this->app->instance('json_response', new JsonResponse());

        $request = new Request($source);
        $this->app->instance('request', $request);
        Facade::clearResolvedInstances();       // re-resolve facades against this request

        try {
            $map = Route::resolveMapFor($request);
            if ($map !== null) {
                // Restore only the resolved map's routes (see $routeSnapshots).
                Route::setRoutes($this->routeSnapshots[$map->getName()] ?? []);
                if ($map->getFile() !== null) {
                    Route::isApiRequest($map->isStateless());
                    if ($middleware = $map->getMiddleware()) {
                        $this->loadMiddlewares($middleware);
                    }
                }
            } else {
                // No map matched (an app with no maps) — restore the fallback table.
                Route::setRoutes($this->routeSnapshots[self::DEFAULT_SNAPSHOT] ?? []);
            }
            Route::dispatch($request);
        } catch (Throwable $e) {
            $this->renderException($request, $e);
        }

        $response = [
            'status'  => BaseResponse::capturedStatus() ?? 200,
            'headers' => BaseResponse::capturedHeaders(),
            'body'    => BaseResponse::capturedBody(),
        ];

        // The whole point of the worker: scrub every per-request static so the next
        // request starts clean against the same long-lived kernel.
        $this->app->flushRequestState();

        $this->requests++;

        return $response;
    }

    /**
     * Render a thrown exception. Prefer the app's bound ExceptionHandler (the production
     * path); if none is bound (or it fails), fall back to a minimal captured response so
     * the worker never dies on a request — an HTTP exception keeps its status code.
     */
    protected function renderException(Request $request, Throwable $e): void
    {
        try {
            if ($this->app->bound(ExceptionHandler::class)) {
                /** @var ExceptionHandler $handler */
                $handler = $this->app->make(ExceptionHandler::class);
                $handler->render($request, $e)->send();
                return;
            }
        } catch (Throwable $ignored) {
            // fall through to the minimal response below
        }

        $status = ($e instanceof BaseHttpException && $e->getCode() >= 400) ? (int) $e->getCode() : 500;
        $this->app->make('response')->plain($e->getMessage() ?: 'Error', $status)->send();
    }

    /**
     * Populate the request-scoped middleware stack from the Kernel for the resolved
     * map's group (mirrors Http\Server::loadMiddlewares, which is private there).
     */
    protected function loadMiddlewares(string $type): void
    {
        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        Route::$middlewareAliases = $kernel->getMiddlewareAliases();
        $middlewares = $kernel->getMiddlewares();

        $groups = $kernel->getMiddlewareGroups();
        if (isset($groups[$type])) {
            array_push($middlewares, ...$groups[$type]);
        }

        Route::$defaultMiddlewares = $middlewares;
        Route::$middlewarePriority = $kernel->getMiddlewarePriority();
    }
}
