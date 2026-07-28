<?php

namespace Eyika\Atom\Octane\Servers;

use Eyika\Atom\Octane\Contracts\Server;
use Eyika\Atom\Octane\Worker;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * Serves the Worker as a RoadRunner PHP worker. The RoadRunner Go binary (`rr serve`)
 * manages the process pool, keep-alive, TLS, and graceful reload; each PHP worker runs
 * this PSR-7 request loop against the booted app.
 *
 * Requires spiral/roadrunner-http + a PSR-17 factory (nyholm/psr7). Point .rr.yaml's
 * `server.command` at a script (or `octane:serve --server=roadrunner`) that reaches here.
 */
class RoadRunnerServer implements Server
{
    /** @param array<string,mixed> $options */
    public function __construct(protected Worker $worker, protected array $options = [])
    {
    }

    public function name(): string
    {
        return 'roadrunner';
    }

    public function start(): void
    {
        if (!class_exists(\Spiral\RoadRunner\Http\PSR7Worker::class)
            || !class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)) {
            throw new RuntimeException(
                'RoadRunner support needs: composer require spiral/roadrunner-http nyholm/psr7'
            );
        }

        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $psr7 = new \Spiral\RoadRunner\Http\PSR7Worker(
            \Spiral\RoadRunner\Worker::create(),
            $factory,
            $factory,
            $factory
        );

        $this->worker->boot();

        while (true) {
            try {
                $request = $psr7->waitRequest();
                if (!$request instanceof ServerRequestInterface) {
                    break; // null → RoadRunner asked the worker to stop
                }
            } catch (Throwable $e) {
                $psr7->getWorker()->error((string) $e);
                continue;
            }

            try {
                $result = $this->worker->handle($this->toSource($request));
                $psr7->respond($this->toResponse($result, $factory));
            } catch (Throwable $e) {
                $psr7->getWorker()->error((string) $e);
            }

            if ($this->worker->shouldRecycle()) {
                break; // exit → RoadRunner starts a fresh worker
            }
        }
    }

    /** Build a Worker source array from a PSR-7 server request. */
    protected function toSource(ServerRequestInterface $request): array
    {
        $uri = $request->getUri();
        $query = $uri->getQuery();

        $server = $request->getServerParams();
        $server['REQUEST_METHOD'] = $request->getMethod();
        $server['REQUEST_URI'] = $uri->getPath() . ($query !== '' ? '?' . $query : '');
        $server['SERVER_PROTOCOL'] = 'HTTP/' . $request->getProtocolVersion();

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $headers[$name];
        }

        return [
            'server'  => $server,
            'query'   => $request->getQueryParams(),
            'post'    => (array) ($request->getParsedBody() ?? []),
            'cookies' => $request->getCookieParams(),
            'files'   => $request->getUploadedFiles(),
            'headers' => $headers,
            'rawBody' => (string) $request->getBody(),
        ];
    }

    /** Build a PSR-7 response from the captured result. */
    protected function toResponse(array $result, ResponseFactoryInterface $factory): \Psr\Http\Message\ResponseInterface
    {
        $response = $factory->createResponse($result['status'] ?? 200);

        foreach ($result['headers'] ?? [] as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $response = $response->withAddedHeader(trim($name), trim($value));
        }

        if (method_exists($factory, 'createStream')) {
            $response = $response->withBody($factory->createStream($result['body'] ?? ''));
        }

        return $response;
    }
}
