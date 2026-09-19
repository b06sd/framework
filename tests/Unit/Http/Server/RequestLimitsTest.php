<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Server;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Http\Exception\HttpException;
use Trunk\Http\Message\ServerRequest;
use Trunk\Http\Server\JsonBody;
use Trunk\Http\Server\RequestLimits;
use Trunk\Http\Server\ServerRequestCreator;
use Trunk\Http\Stream\Stream;

final class RequestLimitsTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>, ?int}>
     */
    public static function framing(): iterable
    {
        yield 'within the limit' => [['CONTENT_LENGTH' => '100'], null];
        yield 'over the limit' => [['CONTENT_LENGTH' => '101'], 413];
        yield 'huge' => [['CONTENT_LENGTH' => '999999999999'], 413];
        yield 'negative' => [['CONTENT_LENGTH' => '-1'], 400];
        yield 'not a number' => [['CONTENT_LENGTH' => 'abc'], 400];
        yield 'list of lengths' => [['CONTENT_LENGTH' => '10, 20'], 400];
        yield 'plus sign' => [['CONTENT_LENGTH' => '+10'], 400];
        yield 'hex' => [['CONTENT_LENGTH' => '0x10'], 400];
        yield 'too many digits' => [['CONTENT_LENGTH' => str_repeat('9', 20)], 400];
        yield 'length with chunked' => [['CONTENT_LENGTH' => '10', 'HTTP_TRANSFER_ENCODING' => 'chunked'], 400];
        yield 'chunked alone' => [['HTTP_TRANSFER_ENCODING' => 'chunked'], null];
        yield 'unknown encoding' => [['HTTP_TRANSFER_ENCODING' => 'gzip, chunked'], 400];
        yield 'obfuscated encoding' => [['HTTP_TRANSFER_ENCODING' => "chunked\r\nX: y"], 400];
        yield 'empty length' => [['CONTENT_LENGTH' => ''], null];
    }

    /**
     * @param array<string, string> $extra
     */
    #[DataProvider('framing')]
    public function test_content_length_and_transfer_encoding_are_validated_before_the_body_is_touched(array $extra, ?int $expected): void
    {
        // Arrange
        $server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'app.test', ...$extra];

        // Act
        $status = $this->statusOf(fn() => $this->creator()->fromArrays($server, body: Stream::fromString('x')));

        // Assert
        self::assertSame($expected, $status);
    }

    public function test_a_lying_content_length_cannot_bypass_the_limit_while_reading(): void
    {
        // Arrange
        $server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'app.test', 'CONTENT_LENGTH' => '10'];
        $request = $this->creator()->fromArrays($server, body: Stream::fromString(str_repeat('x', 5000)));

        // Act
        $status = $this->statusOf(static fn() => (string) $request->getBody());
        $chunked = $this->statusOf(function (): void {
            $r = $this->creator()->fromArrays(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'app.test'], body: Stream::fromString(str_repeat('y', 101)));
            $r->getBody()->getContents();
        });
        $within = $this->creator()->fromArrays(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'app.test'], body: Stream::fromString(str_repeat('z', 100)))->getBody()->getContents();

        // Assert
        self::assertSame(413, $status);
        self::assertSame(413, $chunked);
        self::assertSame(100, \strlen($within));
    }

    public function test_upload_count_and_size_are_bounded_for_flat_and_nested_structures(): void
    {
        // Arrange
        $file = static fn(int $size): array => ['tmp_name' => '/tmp/x', 'size' => $size, 'error' => 0, 'name' => 'a.txt', 'type' => 'text/plain'];
        $server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'app.test'];
        $nested = ['tmp_name' => ['/a', '/b', '/c'], 'size' => [1, 2, 3], 'error' => [0, 0, 0], 'name' => ['a', 'b', 'c'], 'type' => ['t', 't', 't']];

        // Act
        $ok = $this->statusOf(fn() => $this->creator()->fromArrays($server, files: ['a' => $file(50), 'b' => $file(1)]));
        $tooBig = $this->statusOf(fn() => $this->creator()->fromArrays($server, files: ['a' => $file(51)]));
        $tooMany = $this->statusOf(fn() => $this->creator()->fromArrays($server, files: ['a' => $file(1), 'b' => $file(1), 'c' => $file(1)]));
        $nestedTooMany = $this->statusOf(fn() => $this->creator()->fromArrays($server, files: ['docs' => $nested]));
        $nestedOk = $this->statusOf(fn() => new ServerRequestCreator(new RequestLimits(100, 3, 50))->fromArrays($server, files: ['docs' => $nested]));

        // Assert
        self::assertNull($ok);
        self::assertSame([413, 413, 413], [$tooBig, $tooMany, $nestedTooMany]);
        self::assertNull($nestedOk);
    }

    public function test_limits_are_validated_and_read_from_configuration(): void
    {
        // Arrange
        $rejected = 0;

        // Act
        foreach ([[-1], [0, 2000], [0, 0, -5], [0, 0, 0, 0], [0, 0, 0, 999]] as $args) {
            try {
                new RequestLimits(...$args);
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }
        $fromConfig = RequestLimits::fromConfiguration(new \Trunk\Foundation\Configuration(['http' => ['max_body_bytes' => 10, 'max_files' => 1]]));

        // Assert
        self::assertSame(5, $rejected);
        self::assertSame([10, 1, 8 * 1024 * 1024, 16], [$fromConfig->maxBodyBytes, $fromConfig->maxFiles, $fromConfig->maxFileBytes, $fromConfig->maxJsonDepth]);
        self::assertSame(2 * 1024 * 1024, RequestLimits::fromConfiguration(new \Trunk\Foundation\Configuration())->maxBodyBytes);
    }

    /**
     * @return iterable<string, array{string, string, ?int}>
     */
    public static function jsonCases(): iterable
    {
        yield 'valid object' => ['application/json', '{"a":1}', null];
        yield 'charset suffix' => ['application/json; charset=utf-8', '{"a":1}', null];
        yield 'vendor json' => ['application/vnd.api+json', '[1,2]', null];
        yield 'wrong type' => ['text/plain', '{"a":1}', 415];
        yield 'form type' => ['application/x-www-form-urlencoded', '{"a":1}', 415];
        yield 'missing type' => ['', '{"a":1}', 415];
        yield 'invalid json' => ['application/json', '{nope', 400];
        yield 'scalar' => ['application/json', '42', 400];
        yield 'string' => ['application/json', '"x"', 400];
        yield 'null' => ['application/json', 'null', 400];
        yield 'empty' => ['application/json', '', 400];
        yield 'invalid utf8' => ['application/json', "{\"a\":\"\xC3\x28\"}", 400];
        yield 'too deep' => ['application/json', str_repeat('[', 40) . str_repeat(']', 40), 400];
        yield 'too large' => ['application/json', '{"a":"' . str_repeat('x', 200) . '"}', 413];
        yield 'php serialized' => ['application/json', 'O:8:"stdClass":0:{}', 400];
    }

    #[DataProvider('jsonCases')]
    public function test_the_json_reader_enforces_type_size_depth_and_strictness(string $type, string $body, ?int $expected): void
    {
        // Arrange
        $request = new ServerRequest('POST', '/', ['Content-Type' => $type], Stream::fromString($body));

        // Act
        $status = $this->statusOf(static fn() => new JsonBody(new RequestLimits(100, 2, 50, 16))->decode($request));

        // Assert
        self::assertSame($expected, $status);
    }

    public function test_the_json_reader_returns_the_decoded_data(): void
    {
        // Arrange
        $request = new ServerRequest('POST', '/', ['Content-Type' => 'application/json'], Stream::fromString('{"name":"Ada","tags":[1,2]}'));

        // Act & Assert
        self::assertSame(['name' => 'Ada', 'tags' => [1, 2]], new JsonBody()->decode($request));
    }
    private function creator(int $body = 100, int $files = 2, int $fileBytes = 50): ServerRequestCreator
    {
        return new ServerRequestCreator(new RequestLimits($body, $files, $fileBytes));
    }

    private function statusOf(callable $act): ?int
    {
        try {
            $act();
        } catch (HttpException $e) {
            return $e->statusCode;
        }

        return null;
    }
}
