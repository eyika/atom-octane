# Atom Octane

An **Octane-style persistent worker** for the [Atom framework](https://github.com/eyika/atomframework).
Boot the application **once**, then serve many requests against the already-booted kernel —
resetting per-request state in between so nothing leaks from one request to the next.

This package is the end-to-end proof of two pieces of framework work:

- **Package auto-discovery** (`extra.atom.providers`): installing this package immediately
  exposes the `octane:serve` command — no manual provider wiring.
- **Worker-safety** (the framework's `WRK` cluster): an injectable request source, a
  capturable response, and `Application::flushRequestState()` are what make it safe to keep
  a booted application alive across requests.

## Install

```bash
composer require eyika/atom-octane
```

The `OctaneServiceProvider` is discovered automatically from this package's
`composer.json`. Nothing else to register.

## Run

```bash
php console octane:serve --host=127.0.0.1 --port=8090
```

The application boots one time; every incoming connection is served by the same warm
kernel. Pair it with the OPcache preloader (`preload.php` → `Foundation\Preloader`) for a
fully warm process.

## How it works

```
HttpServer (stream_socket_server)         Worker
  accept() ───────────────────────►  handle($source)
  parse raw HTTP → source array          1. resetCapture()               (WRK-02)
                                          2. restore route snapshot        (boot-once)
                                          3. fresh Response/JsonResponse   (no singleton leak)
                                          4. build Request from $source    (WRK-01)
                                          5. dispatch (output captured)    (WRK-02)
                                          6. read captured status/headers/body
  build HTTP response ◄──────────────     7. flushRequestState()          (WRK-03..09)
  write() + close()
```

- **`Worker`** — transport-agnostic. `boot()` wires facades + providers + the route table
  a single time; `handle(array $source)` serves one request and returns
  `['status' => int, 'headers' => string[], 'body' => string]`. It never touches a socket,
  so it is trivially testable (see the framework's `OctaneWorkerTest`).
- **`HttpServer`** — a minimal, dependency-free HTTP/1.1 front end built on PHP's own
  `stream_socket_server`. It exists to prove the story end-to-end; a production deployment
  would swap this transport for Swoole / FrankenPHP / RoadRunner while keeping `Worker`
  untouched.

## Why it's safe to reuse a booted app

Under PHP-FPM every request gets a fresh process, so leaking static state is invisible.
A persistent worker reuses the process, so any per-request static that isn't reset becomes
a cross-request bug. `Worker::handle()` relies on the framework's coordinator,
`Application::flushRequestState()`, which scrubs Auth, the Validator, the Session global,
per-request routing state, resolved facades, and scoped container instances between
requests.

## License

MIT
