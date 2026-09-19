<?php

declare(strict_types=1);

namespace Trunk\Http\Message;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final class Request extends RequestMessage
{
    /**
     * @param array<array-key, mixed> $headers
     */
    public function __construct(string $method, UriInterface|string $uri, array $headers = [], ?StreamInterface $body = null, string $version = '1.1')
    {
        $this->initializeRequest($method, $uri, $headers, $body, $version);
    }
}
