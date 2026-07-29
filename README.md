# Atom Octane

> 📖 **Documentation:** the canonical guide for this package lives in the Atom docs —
> **[Official Packages → atom-octane](https://basttyydev.serv00.net/docs/beta/packages#atom-octane)**.
> This README is a quick reference; the docs cover runtimes, config, and worker recycling in full.

Supercharge the [Atom framework](https://github.com/eyika/atomframework) by serving it from
a **persistent worker**: boot the application **once**, then serve many requests against the
already-booted kernel, resetting per-request state in between. This is where the framework's
worker-safety work (`Application::flushRequestState()`, capturable responses) pays off.

Atom Octane is **runtime-agnostic** — the same `Worker` runs under four servers:

| Runtime | Concurrency | Notes |
|---------|-------------|-------|
| **native** | pcntl fork pool | Dependency-free, pure PHP. Great for simple/POSIX deploys. |
| **swoole** | Swoole workers | `ext-swoole`; coroutines off (the framework is not coroutine-safe). |
| **roadrunner** | RR process pool | Go binary manages the pool, keep-alive, TLS, reload. |
| **frankenphp** | FrankenPHP workers | Caddy/Go server; HTTP/2+3, automatic HTTPS. |

## Install

```bash
composer require eyika/atom-octane
php artisan vendor:publish --tag=octane-config   # optional: config/octane.php
```

The `OctaneServiceProvider` is auto-discovered (`extra.atom.providers`).

## Configure

`config/octane.php` (env-overridable). Key settings:

```php
'server'       => env('OCTANE_SERVER', 'native'),   // native|swoole|roadrunner|frankenphp
'host'         => env('OCTANE_HOST', '127.0.0.1'),
'port'         => (int) env('OCTANE_PORT', 8090),
'workers'      => env('OCTANE_WORKERS', 'auto'),     // native/swoole; "auto" = CPU cores
'max_requests' => (int) env('OCTANE_MAX_REQUESTS', 500),  // recycle after N requests
'max_memory'   => (int) env('OCTANE_MAX_MEMORY', 0),      // recycle over N MB (0 = off)
'request_timeout' => (int) env('OCTANE_REQUEST_TIMEOUT', 30),
```

**Worker recycling** bounds the slow memory growth every long-lived PHP process accumulates
(fragmentation, caches, leaks in app/third-party code): a worker is gracefully restarted after
`max_requests` requests or when it crosses `max_memory`.

## Run

```bash
php artisan octane:serve                                   # uses config's server
php artisan octane:serve --server=swoole --workers=8       # override
php artisan octane:serve --host=0.0.0.0 --port=8080 --max-requests=1000
```

### native (pure PHP)
No dependencies. Forks a pool of workers (needs `ext-pcntl`; single process without it),
each serving keep-alive connections and recycling on quota. Graceful reload with `SIGHUP`,
shutdown with `SIGTERM`. Put nginx/Caddy in front for TLS and static files.

### swoole
```bash
pecl install swoole
php artisan octane:serve --server=swoole --workers=8
```

### roadrunner
```bash
composer require spiral/roadrunner-http nyholm/psr7
# download the `rr` binary, then point .rr.yaml at the worker:
```
```yaml
# .rr.yaml
server:
  command: "php artisan octane:serve --server=roadrunner"
http:
  address: "0.0.0.0:8080"
  pool: { num_workers: 8 }
```
```bash
./rr serve
```
RoadRunner manages the pool, keep-alive, TLS, and graceful reload; our worker just runs the
PSR-7 request loop.

### frankenphp
Run FrankenPHP with a worker script that boots the app and calls the FrankenPHP server:
```php
// public/frankenphp-worker.php
$app = require __DIR__.'/../bootstrap/app.php';
(new \Eyika\Atom\Octane\ServerFactory)::make($app, ['server' => 'frankenphp'])->start();
```
```caddyfile
# Caddyfile
{
    frankenphp { worker ./public/frankenphp-worker.php }
}
example.com {
    root * public/
    php_server
}
```

## Architecture

```
runtime (native/swoole/roadrunner/frankenphp)
    │  translate request → source array
    ▼
Worker::handle($source)          # boot()ed once per process
    restore route snapshot → fresh Response/JsonResponse → build Request (WRK-01)
    → dispatch, output captured (WRK-02) → read status/headers/body
    → flushRequestState() (WRK-03..09)
    │  ← ['status','headers','body']
    ▼
runtime writes the response; recycle the worker when shouldRecycle()
```

- **`Worker`** — transport-agnostic; boots once, `handle(array $source): array`, tracks
  requests + `shouldRecycle()`. Route table is snapshotted **per map** so maps sharing a
  path (web + api both defining `/`) don't collide.
- **`Servers\*`** — one adapter per runtime, each translating the runtime's request into a
  source array and the captured response back out.
- **`ServerFactory`** — builds the configured server + worker from config/overrides.

## Caveats

- The framework's static state is **not coroutine/async-safe** — Swoole runs with coroutines
  off; one request per worker at a time (concurrency = worker count).
- App singletons that hold per-request state must be reset in a provider's boot or via the
  container's `scoped()` bindings — `flushRequestState()` clears framework state, not yours.
- The native server's fork pool + graceful reload require `ext-pcntl` (POSIX only). On
  Windows use Swoole/RoadRunner/FrankenPHP for real concurrency.

## License

MIT
