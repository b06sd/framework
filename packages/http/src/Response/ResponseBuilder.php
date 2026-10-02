<?php

declare(strict_types=1);

namespace Trunk\Http\Response;

use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Trunk\Http\Exception\UnsafeRedirectException;
use Trunk\Http\Stream\Stream;

/**
 * Builds plain responses (HTML, text, JSON, redirects) for controllers. Inject it like any other
 * service; there is no base class and no global helper. Every response carries
 * `X-Content-Type-Options: nosniff`. API applications use this directly and need no view engine.
 *
 * @api
 */
final readonly class ResponseBuilder
{
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(private ResponseFactoryInterface $responses) {}

    /**
     * Sends HTML you have already made safe.
     */
    public function html(string $html, int $status = 200): ResponseInterface
    {
        return $this->make($status, 'text/html; charset=utf-8', $html);
    }

    public function text(string $text, int $status = 200): ResponseInterface
    {
        return $this->make($status, 'text/plain; charset=utf-8', $text);
    }

    /**
     * `<`, `>`, `&`, `'` and `"` are hex-escaped, so the output is also safe if it ends up inside HTML.
     */
    public function json(mixed $data, int $status = 200): ResponseInterface
    {
        $json = json_encode($data, \JSON_THROW_ON_ERROR | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);

        return $this->make($status, 'application/json', $json);
    }

    /**
     * Sends a file to be saved, never shown: `Content-Disposition: attachment`, and a Content-Security-
     * Policy that sandboxes it should a browser display it anyway, so an uploaded HTML or SVG file can
     * never run as a page on your site. `$filename` is what the browser proposes when saving (any
     * Unicode; quotes, slashes and control characters are dropped). A stream is sent in chunks, never
     * read into memory.
     *
     * @param resource|string|StreamInterface $content a stream resource (e.g. Flysystem's readStream()), a string, or a PSR-7 stream
     * @param string                          $type    the media type; anything that is not a plain type/subtype becomes application/octet-stream
     */
    public function download(mixed $content, string $filename, string $type = 'application/octet-stream'): ResponseInterface
    {
        $body = match (true) {
            $content instanceof StreamInterface => $content,
            \is_string($content) => Stream::fromString($content),
            \is_resource($content) => new Stream($content),
            default => throw new InvalidArgumentException('download() takes a stream resource, a string or a PSR-7 stream.'),
        };
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u', '', $filename)) ?: 'download';
        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '_', $name);

        return $this->responses->createResponse(200)
            ->withBody($body)
            ->withHeader('Content-Type', preg_match('#^[a-z0-9!\#$&^_.+-]{1,64}/[a-z0-9!\#$&^_.+-]{1,64}$#Di', $type) === 1 ? $type : 'application/octet-stream')
            ->withHeader('Content-Disposition', \sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($name)))
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function noContent(): ResponseInterface
    {
        return $this->make(204, null, '');
    }

    /**
     * Redirects to a path on this site. Absolute URLs are refused on purpose so that user input
     * can never turn a redirect into an open redirect.
     */
    public function redirect(string $path, int $status = 302): ResponseInterface
    {
        if (!\in_array($status, self::REDIRECT_STATUSES, true)) {
            throw new InvalidArgumentException(\sprintf('%d is not a redirect status.', $status));
        }

        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || preg_match('/[\x00-\x20\x7F\\\\]/', $path) === 1) {
            throw new UnsafeRedirectException('Redirects must target a local path like "/users/5" (no host, whitespace, control characters or backslashes).');
        }

        return $this->make($status, null, '')->withHeader('Location', $path);
    }

    private function make(int $status, ?string $contentType, string $body): ResponseInterface
    {
        $response = $this->responses->createResponse($status)->withHeader('X-Content-Type-Options', 'nosniff');

        if ($contentType !== null) {
            $response = $response->withHeader('Content-Type', $contentType);
        }

        if ($body !== '') {
            $response->getBody()->write($body);
        }

        return $response;
    }
}
