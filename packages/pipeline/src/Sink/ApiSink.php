<?php

declare(strict_types=1);

namespace Trunk\Pipeline\Sink;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Trunk\Pipeline\Exception\PipelineException;
use Trunk\Pipeline\Sink;

/**
 * Posts a chunk as one JSON array to $url. Bring your own PSR-18 client, PSR-17 request and stream
 * factories — see ApiSource's docblock for why that also gives connection reuse for free.
 *
 * @api
 */
final readonly class ApiSink implements Sink
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
        private string $url,
    ) {}

    public function write(array $records): void
    {
        $request = $this->requests->createRequest('POST', $this->url)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream(json_encode($records, \JSON_THROW_ON_ERROR)));

        $response = $this->client->sendRequest($request);

        if ($response->getStatusCode() >= 300) {
            throw new PipelineException(\sprintf('%s answered %d.', $this->url, $response->getStatusCode()));
        }
    }
}
