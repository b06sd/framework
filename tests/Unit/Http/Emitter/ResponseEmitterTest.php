<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Emitter;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
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
        self::assertSame(['status:HTTP/1.1 201 Created', 'header:Content-Type: text/plain', 'header:Content-Length: 5', 'write:hello'], $sapi->calls);
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
            'header:Content-Length: 0',
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
        self::assertSame(['header:Content-Length: 10', 'write:abcd', 'write:efgh', 'write:ij'], \array_slice($sapi->calls, 1));
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

    public function test_no_content_length_is_added_where_it_could_be_wrong(): void
    {
        // Arrange: the handler's own framing, a HEAD answer, and a runtime that compresses output
        $cases = [
            'own Content-Length' => [new FakeSapi(), new Response(200, ['Content-Length' => '3'], Stream::fromString('abc')), true, ['header:Content-Length: 3']],
            'Transfer-Encoding' => [new FakeSapi(), new Response(200, ['Transfer-Encoding' => 'chunked'], Stream::fromString('abc')), true, []],
            'HEAD' => [new FakeSapi(), new Response(200, [], Stream::fromString('abc')), false, []],
            'compressing runtime' => [new FakeSapi(passesThrough: false), new Response(200, [], Stream::fromString('abc')), true, []],
        ];

        foreach ($cases as $case => [$sapi, $response, $withBody, $expected]) {
            // Act
            new ResponseEmitter($sapi)->emit($response, $withBody);

            // Assert
            self::assertSame($expected, array_values(array_filter($sapi->calls, static fn(string $call): bool => str_starts_with($call, 'header:Content-Length'))), $case);
        }
    }

    public function test_never_more_bytes_than_the_declared_length_are_written_even_if_the_body_grows(): void
    {
        // Arrange: the size was taken before reading; the stream has more by the time it is read
        $sapi = new FakeSapi();
        $body = $this->createStub(StreamInterface::class);
        $body->method('isReadable')->willReturn(true);
        $body->method('isSeekable')->willReturn(true);
        $body->method('getSize')->willReturn(4);
        $body->method('eof')->willReturn(false);
        $body->method('read')->willReturnCallback(static fn(int $length): string => substr('abcdefghij', 0, $length));

        // Act
        new ResponseEmitter($sapi)->emit(new Response(200, [], $body));

        // Assert
        self::assertSame(['header:Content-Length: 4', 'write:abcd'], \array_slice($sapi->calls, 1));
    }

    public function test_a_body_of_unknown_size_is_flushed_as_it_is_produced_without_a_length(): void
    {
        // Arrange: a non-seekable stream (a generator, a pipe) is a stream the client should see live
        $sapi = new FakeSapi();
        $body = $this->createStub(StreamInterface::class);
        $body->method('isReadable')->willReturn(true);
        $body->method('isSeekable')->willReturn(false);
        $body->method('getSize')->willReturn(null);
        $body->method('eof')->willReturnOnConsecutiveCalls(false, false, true);
        $body->method('read')->willReturnOnConsecutiveCalls('event 1', 'event 2');

        // Act
        new ResponseEmitter($sapi)->emit(new Response(200, [], $body));

        // Assert
        self::assertSame(['write:event 1', 'flush', 'write:event 2', 'flush'], \array_slice($sapi->calls, 1));
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
