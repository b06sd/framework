<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Message;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Trunk\Http\Message\Request;
use Trunk\Http\Message\Response;
use Trunk\Http\Message\ServerRequest;
use Trunk\Http\Stream\Stream;
use Trunk\Http\Uri\Uri;

final class MessageTest extends TestCase
{
    public function test_a_request_derives_host_header_and_target_from_its_uri(): void
    {
        // Arrange

        // Act
        $request = new Request('GET', 'http://example.com:8080/users?id=1', ['Accept' => 'text/html']);

        // Assert
        self::assertSame(['Host', 'Accept'], array_keys($request->getHeaders()));
        self::assertSame('example.com:8080', $request->getHeaderLine('Host'));
        self::assertSame('/users?id=1', $request->getRequestTarget());
        self::assertSame('GET', $request->getMethod());
    }

    public function test_with_uri_updates_host_unless_told_to_preserve_it(): void
    {
        // Arrange
        $request = new Request('GET', 'http://a.test/');

        // Act
        $replaced = $request->withUri(new Uri('http://b.test/'));
        $preserved = $request->withUri(new Uri('http://b.test/'), true);

        // Assert
        self::assertSame('b.test', $replaced->getHeaderLine('Host'));
        self::assertSame('a.test', $preserved->getHeaderLine('Host'));
    }

    public function test_messages_are_immutable(): void
    {
        // Arrange
        $request = new Request('GET', '/');

        // Act
        $changed = $request->withMethod('POST')->withHeader('X-A', '1')->withProtocolVersion('1.0');

        // Assert
        self::assertSame('GET', $request->getMethod());
        self::assertFalse($request->hasHeader('X-A'));
        self::assertSame('POST', $changed->getMethod());
        self::assertSame('1.0', $changed->getProtocolVersion());
    }

    public function test_the_body_defaults_to_an_empty_stream_and_can_be_replaced(): void
    {
        // Arrange
        $response = new Response();

        // Act
        $replaced = $response->withBody(Stream::fromString('hi'));

        // Assert
        self::assertSame('', (string) $response->getBody());
        self::assertSame('hi', (string) $replaced->getBody());
    }

    public function test_invalid_methods_and_versions_are_rejected(): void
    {
        // Arrange
        $request = new Request('GET', '/');

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $request->withProtocolVersion('9.9');
    }

    public function test_a_response_has_a_default_reason_phrase_and_validates_its_status(): void
    {
        // Arrange
        $response = new Response(404);

        // Act
        $custom = $response->withStatus(499, 'Client Closed');

        // Assert
        self::assertSame('Not Found', $response->getReasonPhrase());
        self::assertSame('Client Closed', $custom->getReasonPhrase());
        $this->expectException(InvalidArgumentException::class);
        $response->withStatus(99);
    }

    public function test_server_request_attributes_and_params_are_immutable(): void
    {
        // Arrange
        $request = new ServerRequest('GET', '/');

        // Act
        $with = $request->withAttribute('id', 7)->withQueryParams(['a' => '1'])->withCookieParams(['c' => 'v']);
        $without = $with->withoutAttribute('id');

        // Assert
        self::assertNull($request->getAttribute('id'));
        self::assertSame(7, $with->getAttribute('id'));
        self::assertSame('fallback', $without->getAttribute('id', 'fallback'));
        self::assertSame(['a' => '1'], $with->getQueryParams());
        self::assertSame(['c' => 'v'], $with->getCookieParams());
    }

    public function test_uploaded_files_must_be_uploaded_file_objects(): void
    {
        // Arrange
        $request = new ServerRequest('POST', '/');

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $request->withUploadedFiles(['file' => 'not-a-file']);
    }

    public function test_the_parsed_body_accepts_arrays(): void
    {
        // Arrange
        $request = new ServerRequest('POST', '/');

        // Act
        $result = $request->withParsedBody(['a' => 1]);

        // Assert
        self::assertSame(['a' => 1], $result->getParsedBody());
    }
}
