<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Pipeline\Sink;

use PHPUnit\Framework\TestCase;
use Trunk\Http\Factory\HttpFactory;
use Trunk\Pipeline\Exception\PipelineException;
use Trunk\Pipeline\Sink\ApiSink;
use Trunk\Tests\Fixtures\Pipeline\FakeHttpClient;

final class ApiSinkTest extends TestCase
{
    public function test_write_posts_the_chunk_as_one_json_array(): void
    {
        // Arrange
        $factory = new HttpFactory();
        $client = new FakeHttpClient($factory->createResponse(201));
        $sink = new ApiSink($client, $factory, $factory, 'https://api.example.test/records');

        // Act
        $sink->write([['id' => 1], ['id' => 2]]);

        // Assert
        self::assertCount(1, $client->requests);
        self::assertSame('POST', $client->requests[0]->getMethod());
        self::assertSame('application/json', $client->requests[0]->getHeaderLine('Content-Type'));
        self::assertSame('[{"id":1},{"id":2}]', (string) $client->requests[0]->getBody());
    }

    public function test_a_non_2xx_response_is_a_clear_error(): void
    {
        // Arrange
        $factory = new HttpFactory();
        $client = new FakeHttpClient($factory->createResponse(422));
        $sink = new ApiSink($client, $factory, $factory, 'https://api.example.test/records');

        // Act & Assert
        $this->expectException(PipelineException::class);
        $sink->write([['id' => 1]]);
    }
}
