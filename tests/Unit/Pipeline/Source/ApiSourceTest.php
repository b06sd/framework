<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Pipeline\Source;

use PHPUnit\Framework\TestCase;
use Trunk\Http\Factory\HttpFactory;
use Trunk\Pipeline\Exception\PipelineException;
use Trunk\Pipeline\Source\ApiSource;
use Trunk\Tests\Fixtures\Pipeline\FakeHttpClient;

final class ApiSourceTest extends TestCase
{
    public function test_read_sends_offset_and_limit_and_decodes_the_json_array(): void
    {
        // Arrange
        $factory = new HttpFactory();
        $response = $factory->createResponse(200)->withBody($factory->createStream(json_encode([['id' => 1], ['id' => 2]], \JSON_THROW_ON_ERROR)));
        $client = new FakeHttpClient($response);
        $source = new ApiSource($client, $factory, 'https://api.example.test/records');

        // Act
        $records = iterator_to_array($source->read(20, 10), false);

        // Assert
        self::assertSame([['id' => 1], ['id' => 2]], $records);
        self::assertSame('https://api.example.test/records?offset=20&limit=10', (string) $client->requests[0]->getUri());
    }

    public function test_read_finds_the_records_under_a_json_key(): void
    {
        // Arrange
        $factory = new HttpFactory();
        $response = $factory->createResponse(200)->withBody($factory->createStream(json_encode(['data' => [['id' => 1]], 'total' => 1], \JSON_THROW_ON_ERROR)));
        $client = new FakeHttpClient($response);
        $source = new ApiSource($client, $factory, 'https://api.example.test/records', jsonKey: 'data');

        // Act & Assert
        self::assertSame([['id' => 1]], iterator_to_array($source->read(0, 10), false));
    }

    public function test_a_non_2xx_response_is_a_clear_error(): void
    {
        // Arrange
        $factory = new HttpFactory();
        $client = new FakeHttpClient($factory->createResponse(500));
        $source = new ApiSource($client, $factory, 'https://api.example.test/records');

        // Act & Assert
        $this->expectException(PipelineException::class);
        iterator_to_array($source->read(0, 10), false);
    }
}
