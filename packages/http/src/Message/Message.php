<?php

declare(strict_types=1);

namespace Trunk\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;
use Trunk\Http\Stream\Stream;

/**
 * Behaviour shared by requests and responses. Instances are immutable apart from the body stream itself.
 *
 * @internal an implementation detail, never a base class for application code
 */
abstract class Message implements MessageInterface
{
    protected Headers $headers;

    private ?StreamInterface $body = null;

    private string $protocolVersion = '1.1';

    final public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    final public function withProtocolVersion(string $version): static
    {
        $new = clone $this;
        $new->protocolVersion = self::assertVersion($version);

        return $new;
    }

    /**
     * @return array<string, list<string>>
     */
    final public function getHeaders(): array
    {
        return $this->headers->all();
    }

    final public function hasHeader(string $name): bool
    {
        return $this->headers->has($name);
    }

    /**
     * @return list<string>
     */
    final public function getHeader(string $name): array
    {
        return $this->headers->get($name);
    }

    final public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->headers->get($name));
    }

    /**
     * Adds the given headers that the message does not have yet, with one copy of the message.
     *
     * @param array<string, array{string, string}> $prepared lowercase name => [name, validated value]
     *
     * @internal used by SecurityHeaders; not part of PSR-7
     */
    final public function withMissingHeaders(array $prepared): static
    {
        $headers = $this->headers->withMissing($prepared);

        if ($headers === $this->headers) {
            return $this;
        }

        $new = clone $this;
        $new->headers = $headers;

        return $new;
    }

    final public function withHeader(string $name, $value): static
    {
        $new = clone $this;
        $new->headers = $this->headers->with($name, $value);

        return $new;
    }

    final public function withAddedHeader(string $name, $value): static
    {
        $new = clone $this;
        $new->headers = $this->headers->withAdded($name, $value);

        return $new;
    }

    final public function withoutHeader(string $name): static
    {
        $new = clone $this;
        $new->headers = $this->headers->without($name);

        return $new;
    }

    final public function getBody(): StreamInterface
    {
        return $this->body ??= Stream::fromString();
    }

    final public function withBody(StreamInterface $body): static
    {
        $new = clone $this;
        $new->body = $body;

        return $new;
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    protected function initialize(array $headers, ?StreamInterface $body, string $version): void
    {
        $this->headers = Headers::fromArray($headers);
        $this->body = $body;
        $this->protocolVersion = self::assertVersion($version);
    }

    private static function assertVersion(string $version): string
    {
        if (!\in_array($version, ['1.0', '1.1', '2', '2.0', '3'], true)) {
            throw new InvalidArgumentException(\sprintf('Unsupported HTTP protocol version "%s".', $version));
        }

        return $version;
    }
}
