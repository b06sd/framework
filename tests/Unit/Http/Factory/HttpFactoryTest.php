<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Factory;

use PHPUnit\Framework\TestCase;
use Trunk\Http\Factory\HttpFactory;

final class HttpFactoryTest extends TestCase
{
    public function test_every_psr_17_factory_method_creates_a_working_object(): void
    {
        // Arrange
        $factory = new HttpFactory();
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);

        // Act
        $request = $factory->createRequest('GET', 'http://example.com/');
        $response = $factory->createResponse(201);
        $server = $factory->createServerRequest('POST', '/x', ['REMOTE_ADDR' => '127.0.0.1']);
        $stream = $factory->createStream('abc');
        $fromResource = $factory->createStreamFromResource($resource);
        $upload = $factory->createUploadedFile($stream, null, \UPLOAD_ERR_OK, 'f.txt');
        $uri = $factory->createUri('https://trunk.dev/path');

        // Assert
        self::assertSame('example.com', $request->getUri()->getHost());
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['REMOTE_ADDR' => '127.0.0.1'], $server->getServerParams());
        self::assertSame('abc', (string) $stream);
        self::assertTrue($fromResource->isWritable());
        self::assertSame(3, $upload->getSize());
        self::assertSame('/path', $uri->getPath());
    }
}
