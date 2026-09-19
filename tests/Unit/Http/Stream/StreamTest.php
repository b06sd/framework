<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Stream;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Trunk\Http\Stream\Stream;

final class StreamTest extends TestCase
{
    public function test_a_string_stream_can_be_read_seeked_and_measured(): void
    {
        // Arrange
        $stream = Stream::fromString('hello world');

        // Act
        $first = $stream->read(5);
        $position = $stream->tell();
        $stream->seek(6);
        $rest = $stream->getContents();

        // Assert
        self::assertSame('hello', $first);
        self::assertSame(5, $position);
        self::assertSame('world', $rest);
        self::assertTrue($stream->eof());
        self::assertSame(11, $stream->getSize());
        self::assertSame('hello world', (string) $stream);
    }

    public function test_writing_appends_at_the_current_position(): void
    {
        // Arrange
        $stream = Stream::fromString();

        // Act
        $written = $stream->write('abc');

        // Assert
        self::assertSame(3, $written);
        self::assertSame('abc', (string) $stream);
    }

    public function test_a_closed_stream_rejects_operations_and_reports_no_size(): void
    {
        // Arrange
        $stream = Stream::fromString('x');
        $stream->close();

        // Act & Assert
        self::assertNull($stream->getSize());
        self::assertSame('', (string) $stream);
        $this->expectException(RuntimeException::class);
        $stream->read(1);
    }

    public function test_detach_returns_the_resource_and_leaves_the_stream_unusable(): void
    {
        // Arrange
        $stream = Stream::fromString('x');

        // Act
        $resource = $stream->detach();

        // Assert
        self::assertIsResource($resource);
        self::assertFalse($stream->isReadable());
        self::assertSame([], $stream->getMetadata());
    }

    public function test_read_only_streams_reject_writes(): void
    {
        // Arrange
        $path = tempnam(sys_get_temp_dir(), 'trunk');
        self::assertIsString($path);
        $stream = Stream::fromFile($path, 'r');

        // Act
        $writable = $stream->isWritable();
        $stream->close();
        unlink($path);

        // Assert
        self::assertFalse($writable);
    }

    public function test_opening_a_missing_file_fails(): void
    {
        // Arrange

        // Act & Assert
        $this->expectException(RuntimeException::class);
        Stream::fromFile('/definitely/not/here');
    }

    public function test_a_negative_read_length_is_rejected(): void
    {
        // Arrange
        $stream = Stream::fromString('abc');

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $stream->read(-1);
    }
}
