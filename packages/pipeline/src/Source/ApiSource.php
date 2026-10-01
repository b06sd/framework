<?php

declare(strict_types=1);

namespace Trunk\Pipeline\Source;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Trunk\Pipeline\Exception\PipelineException;
use Trunk\Pipeline\Source;

/**
 * Reads a paginated JSON API (`GET {$url}?offset=&limit=`, a JSON array of records at $jsonKey, or
 * the whole body if null). Bring your own PSR-18 client and PSR-17 request factory — Trunk does not
 * provide an outbound HTTP client itself. The connection-reuse benefit ("trunking" for an API
 * source) needs no special code here: a Pipeline instance persists for the life of the worker
 * process handling its chunks, so if $client is bound as the usual singleton, the same client (and
 * its keep-alive connection) is reused across every request in the run, the same way
 * Trunk\Database\Connection\ConnectionManager already reuses one database connection per process.
 *
 * @api
 */
final readonly class ApiSource implements Source
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requests,
        private string $url,
        private ?string $jsonKey = null,
    ) {}

    public function read(int $offset, int $limit): iterable
    {
        $separator = str_contains($this->url, '?') ? '&' : '?';
        $request = $this->requests->createRequest('GET', $this->url . $separator . http_build_query(['offset' => $offset, 'limit' => $limit]));
        $response = $this->client->sendRequest($request);

        if ($response->getStatusCode() >= 300) {
            throw new PipelineException(\sprintf('%s answered %d.', $this->url, $response->getStatusCode()));
        }

        $body = json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        $records = $this->jsonKey === null || !\is_array($body) ? $body : ($body[$this->jsonKey] ?? []);

        if (!\is_array($records)) {
            throw new PipelineException(\sprintf('%s did not answer a JSON array%s.', $this->url, $this->jsonKey === null ? '' : ' at "' . $this->jsonKey . '"'));
        }

        yield from $records;
    }
}
