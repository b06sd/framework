<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Pipeline;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Trunk\Http\Factory\HttpFactory;

/**
 * A PSR-18 client that never makes a real request: it records every request it was asked to send
 * and answers with whatever response was queued for it, in order.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    private array $responses;

    public function __construct(ResponseInterface ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? new HttpFactory()->createResponse(200);
    }
}
