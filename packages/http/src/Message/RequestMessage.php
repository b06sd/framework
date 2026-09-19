<?php

declare(strict_types=1);

namespace Trunk\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Trunk\Http\Uri\Uri;

/**
 * Request behaviour shared by client and server requests.
 *
 * @internal an implementation detail, never a base class for application code
 */
abstract class RequestMessage extends Message implements RequestInterface
{
    private string $method;

    private UriInterface $uri;

    private ?string $requestTarget = null;

    final public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath();
        $target = $target === '' ? '/' : $target;

        if ($this->uri->getQuery() !== '') {
            $target .= '?' . $this->uri->getQuery();
        }

        return $target;
    }

    final public function withRequestTarget(string $requestTarget): static
    {
        if (preg_match('/[\x00-\x20\x7F]/', $requestTarget) === 1) {
            throw new InvalidArgumentException('The request target must not contain whitespace or control characters.');
        }

        $new = clone $this;
        $new->requestTarget = $requestTarget;

        return $new;
    }

    final public function getMethod(): string
    {
        return $this->method;
    }

    final public function withMethod(string $method): static
    {
        $new = clone $this;
        $new->method = self::assertMethod($method);

        return $new;
    }

    final public function getUri(): UriInterface
    {
        return $this->uri;
    }

    final public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $new = clone $this;
        $new->uri = $uri;

        if ($uri->getHost() !== '' && (!$preserveHost || !$this->headers->has('Host'))) {
            $new->headers = $this->headers->withFirst('Host', self::hostHeader($uri));
        }

        return $new;
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    protected function initializeRequest(string $method, UriInterface|string $uri, array $headers, ?StreamInterface $body, string $version): void
    {
        $this->method = self::assertMethod($method);
        $this->uri = \is_string($uri) ? new Uri($uri) : $uri;
        $this->initialize($headers, $body, $version);

        if (!$this->headers->has('Host') && $this->uri->getHost() !== '') {
            $this->headers = $this->headers->withFirst('Host', self::hostHeader($this->uri));
        }
    }

    private static function hostHeader(UriInterface $uri): string
    {
        return $uri->getPort() === null ? $uri->getHost() : $uri->getHost() . ':' . $uri->getPort();
    }

    private static function assertMethod(string $method): string
    {
        Headers::assertName($method);

        return $method;
    }
}
