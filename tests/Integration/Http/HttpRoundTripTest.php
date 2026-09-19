<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Trunk\Http\Emitter\ResponseEmitter;
use Trunk\Http\Factory\HttpFactory;
use Trunk\Http\Server\ServerRequestCreator;
use Trunk\Http\Stream\Stream;
use Trunk\Tests\Support\FakeSapi;

final class HttpRoundTripTest extends TestCase
{
    public function test_a_request_from_php_arrays_flows_through_a_handler_to_the_wire(): void
    {
        // Arrange
        $request = new ServerRequestCreator()->fromArrays(
            ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'trunk.dev', 'REQUEST_URI' => '/hello?name=Ada', 'HTTP_ACCEPT' => 'text/plain'],
            ['name' => 'Ada'],
        );
        $handler = static function (ServerRequestInterface $request, ResponseFactoryInterface $responses): \Psr\Http\Message\ResponseInterface {
            $name = $request->getQueryParams()['name'] ?? 'world';

            return $responses->createResponse(200)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withBody(Stream::fromString('Hello, ' . (\is_string($name) ? $name : 'world') . ' from ' . $request->getUri()->getHost()));
        };
        $sapi = new FakeSapi();

        // Act
        new ResponseEmitter($sapi)->emit($handler($request, new HttpFactory()));

        // Assert
        self::assertSame([
            'status:HTTP/1.1 200 OK',
            'header:Content-Type: text/plain; charset=utf-8',
            'write:Hello, Ada from trunk.dev',
        ], $sapi->calls);
    }
}
