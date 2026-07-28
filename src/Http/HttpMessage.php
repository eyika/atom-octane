<?php

namespace Eyika\Atom\Octane\Http;

/**
 * Dependency-free HTTP/1.1 request parsing + response building for the native server.
 * Kept separate from the socket loop so it can be unit-tested and shared.
 */
class HttpMessage
{
    /**
     * Read one HTTP request (headers + Content-Length body) off a connected socket.
     * Returns '' when the peer closed or nothing arrived before the idle timeout.
     */
    public static function read($conn, int $maxSize = 10485760): string
    {
        $data = '';
        while (strpos($data, "\r\n\r\n") === false) {
            $chunk = @fread($conn, 8192);
            if ($chunk === '' || $chunk === false) {
                return $data; // closed / timed out mid-headers
            }
            $data .= $chunk;
            if (strlen($data) > $maxSize) {
                return $data;
            }
        }

        [$head, $body] = explode("\r\n\r\n", $data, 2);
        if (preg_match('/Content-Length:\s*(\d+)/i', $head, $m)) {
            $need = (int) $m[1];
            while (strlen($body) < $need && strlen($data) <= $maxSize) {
                $chunk = @fread($conn, 8192);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                $body .= $chunk;
            }
        }

        return $head . "\r\n\r\n" . $body;
    }

    /**
     * Parse a raw HTTP/1.1 request into a Worker source array plus protocol metadata:
     * ['source' => array, 'version' => '1.1', 'keep_alive' => bool].
     */
    public static function parse(string $raw): array
    {
        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $lines = explode("\r\n", $head);
        $requestLine = array_shift($lines) ?? '';
        [$method, $target, $proto] = array_pad(explode(' ', trim($requestLine)), 3, '');
        $method = strtoupper($method ?: 'GET');
        $target = $target ?: '/';
        $version = str_contains($proto, '1.0') ? '1.0' : '1.1';

        $headers = [];
        foreach ($lines as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $headers[trim($k)] = trim($v);
        }

        $parts = parse_url($target) ?: [];
        parse_str($parts['query'] ?? '', $query);

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

        $server = [
            'REQUEST_METHOD'  => $method,
            'REQUEST_URI'     => $target,
            'PATH_INFO'       => $parts['path'] ?? '/',
            'QUERY_STRING'    => $parts['query'] ?? '',
            'SERVER_PROTOCOL' => 'HTTP/' . $version,
            'HTTP_HOST'       => $host,
            'SERVER_NAME'     => explode(':', $host)[0],
            'CONTENT_TYPE'    => $contentType,
            'HTTPS'           => '',
        ];
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }

        // Keep-alive: default on for 1.1 (off if "Connection: close"), off for 1.0
        // (on only if "Connection: keep-alive").
        $connection = strtolower($headers['Connection'] ?? '');
        $keepAlive = $version === '1.1'
            ? ($connection !== 'close')
            : ($connection === 'keep-alive');

        return [
            'source' => [
                'server'  => $server,
                'query'   => $query,
                'post'    => $post,
                'cookies' => $cookies,
                'files'   => [],
                'headers' => $headers,
                'rawBody' => $body,
            ],
            'version'    => $version,
            'keep_alive' => $keepAlive,
        ];
    }

    /**
     * Serialize the worker's captured response to an HTTP/1.1 message.
     *
     * @param array{status?:int,headers?:string[],body?:string} $response
     */
    public static function build(array $response, bool $keepAlive = false, string $version = '1.1'): string
    {
        $status = $response['status'] ?? 200;
        $headers = $response['headers'] ?? [];
        $body = $response['body'] ?? '';

        $reason = self::REASONS[$status] ?? 'OK';
        $out = "HTTP/{$version} {$status} {$reason}\r\n";

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
            $out .= 'Connection: ' . ($keepAlive ? 'keep-alive' : 'close') . "\r\n";
        }

        return $out . "\r\n" . $body;
    }

    public const REASONS = [
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified',
        307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 409 => 'Conflict', 419 => 'Page Expired',
        422 => 'Unprocessable Entity', 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];
}
