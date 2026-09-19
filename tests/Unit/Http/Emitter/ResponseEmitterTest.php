<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Emitter;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Trunk\Http\Emitter\ResponseEmitter;
use Trunk\Http\Exception\EmitterException;
use Trunk\Http\Message\Response;
use Trunk\Http\Stream\Stream;
use Trunk\Tests\Support\FakeSapi;

final class ResponseEmitterTest extends TestCase
{
    public function test_status_headers_and_body_are_emitted_in_order(): void
    {
        // Arrange
        $sapi = new FakeSapi();
        $response = new Response(201, ['Content-Type' => 'text/plain'], Stream::fromString('hello'));

        // Act
        new ResponseEmitter($sapi)->emit($response);

        // Assert
        self::assertSame(['status:HTTP/1.1 201 Created', 'header:Content-Type: text/plain', 'write:hello'], $sapi->calls);
    }

    public function test_multiple_values_become_separate_lines_and_cookies_never_replace(): void
    {
        // Arrange
        $sapi = new FakeSapi();
        $response = new Response(200, ['Vary' => ['Accept', 'Origin'], 'Set-Cookie' => ['a=1', 'b=2']]);

        // Act
        new ResponseEmitter($sapi)->emit($response);

        // Assert
        self::assertSame([
            'status:HTTP/1.1 200 OK',
            'header:Vary: Accept',
            'header:Vary: Origin (add)',
            'header:Set-Cookie: a=1 (add)',
            'header:Set-Cookie: b=2 (add)',
        ], $sapi->calls);
    }

    public function test_the_body_is_streamed_in_chunks(): void
    {
        // Arrange
        $sapi = new FakeSapi();
        $response = new Response(200, [], Stream::fromString('abcdefghij'));

        // Act
        new ResponseEmitter($sapi, 4)->emit($response);

        // Assert
        self::assertSame(['write:abcd', 'write:efgh', 'write:ij'], \array_slice($sapi->calls, 1));
    }

    public function test_responses_that_forbid_a_body_are_emitted_without_one(): void
    {
        // Arrange
        $sapi = new FakeSapi();
        $response = new Response(204, [], Stream::fromString('ignored'));

        // Act
        new ResponseEmitter($sapi)->emit($response);

        // Assert
        self::assertSame(['status:HTTP/1.1 204 No Content'], $sapi->calls);
    }

    public function test_emission_is_refused_once_headers_have_been_sent(): void
    {
        // Arrange
        $sapi = new FakeSapi(headersSent: true);

        // Act & Assert
        $this->expectException(EmitterException::class);
        new ResponseEmitter($sapi)->emit(new Response());
    }

    public function test_a_foreign_response_with_an_injected_header_is_rejected_before_any_output(): void
    {
        // Arrange
        $sapi = new FakeSapi();
        $foreign = $this->createStub(ResponseInterface::class);
        $foreign->method('getStatusCode')->willReturn(200);
        $foreign->method('getReasonPhrase')->willReturn('OK');
        $foreign->method('getProtocolVersion')->willReturn('1.1');
        $foreign->method('getHeaders')->willReturn(['X-A' => ["ok\r\nSet-Cookie: session=stolen"]]);

        // Act
        try {
            new ResponseEmitter($sapi)->emit($foreign);
            self::fail('Expected an EmitterException.');
        } catch (EmitterException) {
            // Assert
            self::assertSame([], $sapi->calls);
        }
    }
}
