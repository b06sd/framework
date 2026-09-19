<?php

declare(strict_types=1);

namespace Trunk\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

final class ServerRequest extends RequestMessage implements ServerRequestInterface
{
    /** @var array<array-key, mixed> */
    private array $queryParams = [];

    /** @var array<array-key, mixed> */
    private array $uploadedFiles = [];

    /** @var array<array-key, mixed> */
    private array $cookieParams = [];

    /** @var array<array-key, mixed>|object|null */
    private array|object|null $parsedBody = null;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<array-key, mixed> $headers
     * @param array<array-key, mixed> $serverParams
     */
    public function __construct(
        string $method,
        UriInterface|string $uri,
        array $headers = [],
        ?StreamInterface $body = null,
        string $version = '1.1',
        private readonly array $serverParams = [],
    ) {
        $this->initializeRequest($method, $uri, $headers, $body, $version);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    /**
     * @param array<array-key, mixed> $cookies
     */
    public function withCookieParams(array $cookies): static
    {
        $new = clone $this;
        $new->cookieParams = $cookies;

        return $new;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * @param array<array-key, mixed> $query
     */
    public function withQueryParams(array $query): static
    {
        $new = clone $this;
        $new->queryParams = $query;

        return $new;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    /**
     * @param array<array-key, mixed> $uploadedFiles
     */
    public function withUploadedFiles(array $uploadedFiles): static
    {
        self::assertUploadedFiles($uploadedFiles);
        $new = clone $this;
        $new->uploadedFiles = $uploadedFiles;

        return $new;
    }

    /**
     * @return array<array-key, mixed>|object|null
     */
    public function getParsedBody(): array|object|null
    {
        return $this->parsedBody;
    }

    /**
     * @param array<array-key, mixed>|object|null $data
     */
    public function withParsedBody($data): static
    {
        // The typed property rejects anything but null, array or object with a TypeError.
        $new = clone $this;
        $new->parsedBody = $data;

        return $new;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, $default = null): mixed
    {
        return \array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    public function withAttribute(string $name, $value): static
    {
        $new = clone $this;
        $new->attributes[$name] = $value;

        return $new;
    }

    public function withoutAttribute(string $name): static
    {
        $new = clone $this;
        unset($new->attributes[$name]);

        return $new;
    }

    /**
     * @param array<array-key, mixed> $files
     */
    private static function assertUploadedFiles(array $files): void
    {
        foreach ($files as $file) {
            if (\is_array($file)) {
                self::assertUploadedFiles($file);
            } elseif (!$file instanceof UploadedFileInterface) {
                throw new InvalidArgumentException('Uploaded files must be UploadedFileInterface instances or nested arrays of them.');
            }
        }
    }
}
