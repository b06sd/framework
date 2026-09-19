<?php

declare(strict_types=1);

namespace Trunk\Http\Response;

use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Trunk\Http\Exception\UnsafeRedirectException;

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
