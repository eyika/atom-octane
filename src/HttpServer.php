<?php

namespace Eyika\Atom\Octane;

use RuntimeException;
use Throwable;

/**
 * A minimal, dependency-free HTTP/1.1 front end for the Worker, built on PHP's own
 * stream_socket_server — no Swoole / ReactPHP / FrankenPHP required. It exists to prove
 * the packaging + worker-safety story end-to-end; a production deployment would swap this
 * transport for one of those runtimes while keeping the same Worker untouched.
 *
 * One connection = one request = one Worker::handle() call. The socket accept loop and
 * the framework request lifecycle stay cleanly separated.
 */
class HttpServer
{
    protected Worker $worker;
    /** @var callable|null */
    protected $onLog;

    public function __construct(Worker $worker, ?callable $onLog = null)
    {
        $this->worker = $worker;
        $this->onLog = $onLog;
    }

    /** Boot the app once, then accept + serve connections forever. Blocks. */
    public function serve(string $host = '127.0.0.1', int $port = 8090): void
    {
        $this->worker->boot();

        $server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
        if ($server === false) {
            throw new RuntimeException("Cannot bind {$host}:{$port} — {$errstr} ({$errno})");
        }

        $this->log("Atom Octane worker listening on http://{$host}:{$port}");

        while (true) {
            $conn = @stream_socket_accept($server, -1);
            if ($conn === false) {
                continue;
            }
            $this->handleConnection($conn);
        }
    }

    /** Read one request off a connected socket, dispatch it, write the response back. */
    public function handleConnection($conn): void
    {
        try {
            $raw = $this->readHttpRequest($conn);
            if ($raw === '') {
                return;
            }
            $response = $this->worker->handle($this->parseRequest($raw));
            fwrite($conn, $this->buildHttpResponse($response));
        } catch (Throwable $e) {
            $body = 'Internal Server Error';
            fwrite($conn, "HTTP/1.1 500 Internal Server Error\r\nContent-Length: " . strlen($body)
                . "\r\nConnection: close\r\n\r\n" . $body);
            $this->log('worker error: ' . $e->getMessage());
        } finally {
            @fclose($conn);
        }
    }

    /** Read request headers, then the Content-Length body, off the socket. */
    protected function readHttpRequest($conn): string
    {
        stream_set_timeout($conn, 5);
        $data = '';

        while (strpos($data, "\r\n\r\n") === false) {
            $chunk = fread($conn, 8192);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $data .= $chunk;
            if (strlen($data) > 1048576) { // 1 MB header cap
                break;
            }
        }

        if (strpos($data, "\r\n\r\n") === false) {
            return $data;
        }

        [$head, $body] = explode("\r\n\r\n", $data, 2);
        if (preg_match('/Content-Length:\s*(\d+)/i', $head, $m)) {
            $len = (int) $m[1];
            while (strlen($body) < $len) {
                $chunk = fread($conn, 8192);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                $body .= $chunk;
            }
        }

        return $head . "\r\n\r\n" . $body;
    }

    /** Parse a raw HTTP/1.1 request into a Request source array (see Worker::handle). */
    public function parseRequest(string $raw): array
    {
        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $lines = explode("\r\n", $head);
        $requestLine = array_shift($lines) ?? '';
        [$method, $target] = array_pad(explode(' ', $requestLine), 3, '');
        $method = strtoupper($method ?: 'GET');
        $target = $target ?: '/';

        $headers = [];
        foreach ($lines as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $headers[trim($k)] = trim($v);
        }

        $parts = parse_url($target) ?: [];
        $path = $parts['path'] ?? '/';
        $queryStr = $parts['query'] ?? '';
        parse_str($queryStr, $query);

        $cookies = [];
        if (isset($headers['Cookie'])) {
            foreach (explode(';', $headers['Cookie']) as $pair) {
                if (strpos($pair, '=') === false) {
                    continue;
                }
                [$k, $v] = explode('=', $pair, 2);
                $cookies[trim($k)] = urldecode(trim($v));
            }
        }

        $contentType = $headers['Content-Type'] ?? '';
        $post = [];
        if (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
            parse_str($body, $post);
        }

        $host = $headers['Host'] ?? 'localhost';

        return [
            'server' => [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI'    => $target,
                'PATH_INFO'      => $path,
                'QUERY_STRING'   => $queryStr,
                'HTTP_HOST'      => $host,
                'SERVER_NAME'    => explode(':', $host)[0],
                'CONTENT_TYPE'   => $contentType,
                'HTTPS'          => '',
            ],
            'query'   => $query,
            'post'    => $post,
            'cookies' => $cookies,
            'files'   => [],
            'headers' => $headers,
            'rawBody' => $body,
        ];
    }

    /** Serialize the worker's captured response to an HTTP/1.1 message. */
    public function buildHttpResponse(array $response): string
    {
        $status = $response['status'] ?? 200;
        $headers = $response['headers'] ?? [];
        $body = $response['body'] ?? '';

        $reason = self::REASONS[$status] ?? 'OK';
        $out = "HTTP/1.1 {$status} {$reason}\r\n";

        $hasLength = false;
        $hasConnection = false;
        foreach ($headers as $h) {
            $out .= $h . "\r\n";
            if (stripos($h, 'Content-Length:') === 0) {
                $hasLength = true;
            }
            if (stripos($h, 'Connection:') === 0) {
                $hasConnection = true;
            }
        }
        if (!$hasLength) {
            $out .= 'Content-Length: ' . strlen($body) . "\r\n";
        }
        if (!$hasConnection) {
            $out .= "Connection: close\r\n";
        }

        return $out . "\r\n" . $body;
    }

    protected function log(string $message): void
    {
        if ($this->onLog !== null) {
            ($this->onLog)($message);
        }
    }

    protected const REASONS = [
        200 => 'OK', 201 => 'Created', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 422 => 'Unprocessable Entity',
        429 => 'Too Many Requests', 500 => 'Internal Server Error', 503 => 'Service Unavailable',
    ];
}
