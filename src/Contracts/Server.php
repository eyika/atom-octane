<?php

namespace Eyika\Atom\Octane\Contracts;

/**
 * A runtime that drives a {@see \Eyika\Atom\Octane\Worker}: it boots the worker once and
 * serves requests against it until stopped. Implementations wrap a concrete runtime —
 * the pure-PHP socket server, Swoole, RoadRunner, or FrankenPHP.
 */
interface Server
{
    /** Boot the worker and serve requests until the process is stopped. Blocks. */
    public function start(): void;

    /** A short identifier for this server (e.g. "swoole"). */
    public function name(): string;
}
