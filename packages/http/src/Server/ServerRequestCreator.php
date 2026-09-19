<?php

declare(strict_types=1);

namespace Trunk\Http\Server;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Trunk\Error\ErrorCode;
use Trunk\Http\Exception\HttpException;
use Trunk\Http\Message\ServerRequest;
use Trunk\Http\Stream\BoundedStream;
use Trunk\Http\Stream\Stream;
use Trunk\Http\Upload\UploadedFile;
use Trunk\Http\Uri\Uri;

/**
 * Builds a ServerRequest from PHP's request arrays. `fromGlobals()` is the single place the
 * superglobals are read; everything else is a pure function of its arguments.
 *
 * X-Forwarded-Proto/Host/Port/For are believed only when the TCP peer (REMOTE_ADDR) is listed in
 * `http.trusted_proxies`; by default none is, so a client can never choose the scheme, host or its
 * own address. The RFC 7239 `Forwarded` header is never read. The resolved client address is the
 * `client_ip` request attribute.
 */
final class ServerRequestCreator
{
    public const string CLIENT_IP_ATTRIBUTE = 'client_ip';
    private const string HOST_PATTERN = '/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9._~%!$&\'()*+,;=-]+)(?::(\d{1,5}))?$/D';

    public function __construct(private readonly RequestLimits $limits = new RequestLimits(), private readonly TrustedProxies $proxies = new TrustedProxies()) {}

    public function fromGlobals(): ServerRequest
    {
        return $this->fromArrays($_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, Stream::fromFile('php://input'));
    }

    /**
     * @param array<array-key, mixed> $server
     * @param array<array-key, mixed> $get
     * @param array<array-key, mixed>|null $post
     * @param array<array-key, mixed> $cookies
     * @param array<array-key, mixed> $files
     */
    public function fromArrays(array $server, array $get = [], ?array $post = null, array $cookies = [], array $files = [], ?StreamInterface $body = null): ServerRequest
    {
        $method = $this->string($server, 'REQUEST_METHOD') ?? 'GET';
        $this->assertTargetLength($server);
        $headers = $this->headers($server);
        $this->assertBodyHeaders($server);
        $body = $body === null ? null : new BoundedStream($body, $this->limits->maxBodyBytes);

        $request = new ServerRequest($method, $this->uri($server), $headers, $body, $this->protocol($server), $server)
            ->withQueryParams($get)
            ->withCookieParams($cookies)
            ->withUploadedFiles($this->normalizeFiles($files));
        $client = $this->clientAddress($server);
        $request = $client === null ? $request : $request->withAttribute(self::CLIENT_IP_ATTRIBUTE, $client);

        $this->assertUploads($files);

        return $method === 'POST' && $post !== null && $this->isFormRequest($request) ? $request->withParsedBody($post) : $request;
    }

    /**
     * Rejects framing that could be used for request smuggling and bodies declared too large, before
     * anything is read: a malformed or oversized Content-Length, or Content-Length together with
     * Transfer-Encoding (RFC 9112 section 6.3).
     *
     * @param array<array-key, mixed> $server
     *
     * @throws HttpException
     */
    private function assertBodyHeaders(array $server): void
    {
        $length = $this->string($server, 'CONTENT_LENGTH');
        $encoding = $this->string($server, 'HTTP_TRANSFER_ENCODING');

        if ($length !== null && $length !== '') {
            if (preg_match('/^\d{1,12}$/D', $length) !== 1) {
                throw new HttpException(400, 'Malformed Content-Length.', code: ErrorCode::BadRequest->value);
            }

            if ($encoding !== null && $encoding !== '') {
                throw new HttpException(400, 'Content-Length together with Transfer-Encoding.', code: ErrorCode::BadRequest->value);
            }

            if ((int) $length > $this->limits->maxBodyBytes) {
                throw new HttpException(413, 'The request body exceeds the configured limit.', code: ErrorCode::PayloadTooLarge->value);
            }
        }

        if ($encoding !== null && $encoding !== '' && !\in_array(strtolower(trim($encoding)), ['chunked', 'identity'], true)) {
            throw new HttpException(400, 'Unsupported Transfer-Encoding.', code: ErrorCode::BadRequest->value);
        }
    }

    /**
     * @param array<array-key, mixed> $files
     *
     * @throws HttpException
     */
    private function assertUploads(array $files): void
    {
        $count = 0;
        $this->countUploads($files, $count);
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private function countUploads(array $node, int &$count): void
    {
        if (isset($node['tmp_name'])) {
            $tmp = $node['tmp_name'];
            $sizes = $node['size'] ?? null;

            foreach (\is_array($tmp) ? array_keys($tmp) : [null] as $key) {
                ++$count;
                $size = $key === null ? $sizes : (\is_array($sizes) ? ($sizes[$key] ?? null) : null);

                if ($count > $this->limits->maxFiles) {
                    throw new HttpException(413, 'Too many uploaded files.', code: ErrorCode::PayloadTooLarge->value);
                }

                if (\is_int($size) && $size > $this->limits->maxFileBytes) {
                    throw new HttpException(413, 'An uploaded file exceeds the configured limit.', code: ErrorCode::PayloadTooLarge->value);
                }
            }

            return;
        }

        foreach ($node as $child) {
            if (\is_array($child)) {
                $this->countUploads($child, $count);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $server
     *
     * @throws HttpException 414 when the request target is longer than the limit
     */
    private function assertTargetLength(array $server): void
    {
        $target = $this->string($server, 'REQUEST_URI');

        if ($target !== null && \strlen($target) > $this->limits->maxUriBytes) {
            throw new HttpException(414, 'The request target is too long.');
        }
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private function uri(array $server): Uri
    {
        $https = $this->string($server, 'HTTPS');
        $scheme = $https !== null && $https !== '' && strtolower($https) !== 'off' ? 'https' : 'http';
        $hostHeader = $this->string($server, 'HTTP_HOST') ?? $this->string($server, 'SERVER_NAME') ?? '';
        $forwarded = $this->fromTrustedProxy($server);

        if ($forwarded) {
            $proto = $this->lastForwarded($server, 'HTTP_X_FORWARDED_PROTO');
            $scheme = $proto !== null && \in_array(strtolower($proto), ['http', 'https'], true) ? strtolower($proto) : $scheme;
            $host = $this->lastForwarded($server, 'HTTP_X_FORWARDED_HOST');
            $hostHeader = $host !== null && preg_match(self::HOST_PATTERN, $host) === 1 ? $host : $hostHeader;
        }

        $uri = new Uri()->withScheme($scheme);

        if ($hostHeader !== '') {
            if (preg_match(self::HOST_PATTERN, $hostHeader, $m) !== 1) {
                throw new InvalidArgumentException('The Host header is malformed.');
            }

            $uri = $uri->withHost($m[1]);
            $forwardedPort = $forwarded ? $this->lastForwarded($server, 'HTTP_X_FORWARDED_PORT') : null;
            if (($m[2] ?? '') !== '' && (int) $m[2] === 0) {
                throw new InvalidArgumentException('The Host header is malformed.');
            }

            $port = ($m[2] ?? '') !== '' ? (int) $m[2] : ($forwardedPort !== null && ctype_digit($forwardedPort) && (int) $forwardedPort >= 1 && (int) $forwardedPort <= 65535 ? (int) $forwardedPort : $this->serverPort($server));
            $uri = $uri->withPort($port);
        }

        $target = $this->string($server, 'REQUEST_URI') ?? '/';

        if (($target[0] ?? '') !== '/') {
            // Absolute-form or asterisk-form targets: keep only path and query, never the authority.
            $parsed = $target === '*' ? new Uri() : new Uri($target);

            return $uri->withPath($parsed->getPath())->withQuery($parsed->getQuery());
        }

        [$path, $query] = array_pad(explode('?', $target, 2), 2, '');

        return $uri->withPath($path)->withQuery($query);
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private function fromTrustedProxy(array $server): bool
    {
        $peer = $this->string($server, 'REMOTE_ADDR');

        return $peer !== null && !$this->proxies->isEmpty() && $this->proxies->isTrusted($peer);
    }

    /**
     * The value the nearest (trusted) proxy appended: the last element of a comma list.
     *
     * @param array<array-key, mixed> $server
     */
    private function lastForwarded(array $server, string $key): ?string
    {
        $value = $this->string($server, $key);

        if ($value === null) {
            return null;
        }

        $last = trim(substr(strrchr(',' . $value, ',') ?: '', 1));

        return $last === '' ? null : $last;
    }

    /**
     * The address of the real client: the TCP peer, or, behind trusted proxies, the first address in
     * X-Forwarded-For (read right to left) that is not itself a trusted proxy.
     *
     * @param array<array-key, mixed> $server
     */
    private function clientAddress(array $server): ?string
    {
        $peer = $this->string($server, 'REMOTE_ADDR');

        if ($peer === null || filter_var($peer, \FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $chain = $this->string($server, 'HTTP_X_FORWARDED_FOR');

        if ($chain === null || $this->proxies->isEmpty()) {
            return $peer;
        }

        $client = $peer;

        foreach (array_reverse(array_map(trim(...), explode(',', $chain))) as $hop) {
            if (!$this->proxies->isTrusted($client) || filter_var($hop, \FILTER_VALIDATE_IP) === false) {
                break;
            }

            $client = $hop;
        }

        return $client;
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private function serverPort(array $server): ?int
    {
        $port = $this->string($server, 'SERVER_PORT');

        return $port !== null && ctype_digit($port) ? (int) $port : null;
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private function protocol(array $server): string
    {
        $protocol = $this->string($server, 'SERVER_PROTOCOL') ?? '';

        return preg_match('~^HTTP/(1\.0|1\.1|2(?:\.0)?|3)$~D', $protocol, $m) === 1 ? $m[1] : '1.1';
    }

    /**
     * @param array<array-key, mixed> $server
     *
     * @return array<string, string>
     */
    private function headers(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!\is_string($key) || !\is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                // HTTP_PROXY is skipped: it is the "httpoxy" vector for CGI-style environments.
                if ($key !== 'HTTP_PROXY') {
                    $headers[$this->headerName(substr($key, 5))] = $value;
                }
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                if ($value !== '') {
                    $headers[$this->headerName($key)] = $value;
                }
            }
        }

        return $headers;
    }

    private function headerName(string $key): string
    {
        return str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $key))));
    }

    private function isFormRequest(ServerRequestInterface $request): bool
    {
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));

        return $type === 'application/x-www-form-urlencoded' || $type === 'multipart/form-data';
    }

    /**
     * @param array<array-key, mixed> $files
     *
     * @return array<array-key, mixed>
     */
    private function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $value) {
            $normalized[$key] = $this->normalizeEntry($value);
        }

        return $normalized;
    }

    /**
     * @return UploadedFileInterface|array<array-key, mixed>
     */
    private function normalizeEntry(mixed $entry): UploadedFileInterface|array
    {
        if (!\is_array($entry)) {
            throw new InvalidArgumentException('Malformed uploaded file structure.');
        }

        if (!isset($entry['tmp_name'])) {
            return $this->normalizeFiles($entry);
        }

        if (\is_array($entry['tmp_name'])) {
            $nested = [];

            foreach (array_keys($entry['tmp_name']) as $key) {
                $nested[$key] = $this->normalizeEntry([
                    'tmp_name' => $entry['tmp_name'][$key] ?? null,
                    'size' => \is_array($entry['size'] ?? null) ? ($entry['size'][$key] ?? null) : null,
                    'error' => \is_array($entry['error'] ?? null) ? ($entry['error'][$key] ?? null) : null,
                    'name' => \is_array($entry['name'] ?? null) ? ($entry['name'][$key] ?? null) : null,
                    'type' => \is_array($entry['type'] ?? null) ? ($entry['type'][$key] ?? null) : null,
                ]);
            }

            return $nested;
        }

        $tmpName = $entry['tmp_name'];
        $size = $entry['size'] ?? null;
        $error = $entry['error'] ?? \UPLOAD_ERR_NO_FILE;
        $name = $entry['name'] ?? null;
        $type = $entry['type'] ?? null;

        if (!\is_string($tmpName) || !\is_int($error) || ($size !== null && !\is_int($size))) {
            throw new InvalidArgumentException('Malformed uploaded file entry.');
        }

        return new UploadedFile(
            $tmpName,
            $size,
            $error,
            \is_string($name) ? $name : null,
            \is_string($type) ? $type : null,
            sapiUpload: true,
        );
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private function string(array $server, string $key): ?string
    {
        $value = $server[$key] ?? null;

        return \is_string($value) ? $value : null;
    }
}
