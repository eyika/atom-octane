<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Octane Server
    |--------------------------------------------------------------------------
    |
    | The runtime that serves the application. One of: "native" (dependency-free
    | pure-PHP socket server), "swoole", "roadrunner", or "frankenphp". Override
    | on the CLI with `octane:serve --server=...`.
    |
    */

    'server' => env('OCTANE_SERVER', 'native'),

    /*
    |--------------------------------------------------------------------------
    | Bind Address
    |--------------------------------------------------------------------------
    */

    'host' => env('OCTANE_HOST', '127.0.0.1'),
    'port' => (int) env('OCTANE_PORT', 8090),

    /*
    |--------------------------------------------------------------------------
    | Workers
    |--------------------------------------------------------------------------
    |
    | Number of worker processes (native fork pool / Swoole workers). "auto"
    | uses the CPU core count. RoadRunner and FrankenPHP manage workers via
    | their own config, so this is ignored for those runtimes.
    |
    */

    'workers' => env('OCTANE_WORKERS', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Worker Recycling
    |--------------------------------------------------------------------------
    |
    | A worker is gracefully recycled (its process restarted) after serving this
    | many requests, or when its memory crosses the ceiling (MB). This bounds the
    | slow memory growth every long-lived PHP process accumulates. 0 = unlimited.
    |
    */

    'max_requests' => (int) env('OCTANE_MAX_REQUESTS', 500),
    'max_memory'   => (int) env('OCTANE_MAX_MEMORY', 0),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Maximum time a single request may take before the worker is considered
    | stuck and killed/recycled. Honoured by the native + Swoole servers.
    |
    */

    'request_timeout' => (int) env('OCTANE_REQUEST_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Native Server: HTTP Keep-Alive
    |--------------------------------------------------------------------------
    */

    'keep_alive'        => (bool) env('OCTANE_KEEP_ALIVE', true),
    'keep_alive_timeout' => (int) env('OCTANE_KEEP_ALIVE_TIMEOUT', 5),
    'max_request_size'  => (int) env('OCTANE_MAX_REQUEST_SIZE', 10 * 1024 * 1024), // bytes

];
