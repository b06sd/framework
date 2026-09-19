<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Upload;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Trunk\Http\Stream\Stream;
use Trunk\Http\Upload\UploadedFile;

final class UploadedFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-upload-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map(\unlink(...), glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    public function test_a_stream_backed_upload_can_be_moved_once(): void
    {
        // Arrange
        $file = new UploadedFile(Stream::fromString('payload'), 7, \UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        $target = $this->directory . '/out.txt';

        // Act
        $file->moveTo($target);

        // Assert
        self::assertSame('payload', file_get_contents($target));
        self::assertSame('a.txt', $file->getClientFilename());
        self::assertSame('text/plain', $file->getClientMediaType());
        self::assertSame(7, $file->getSize());
        $this->expectException(RuntimeException::class);
        $file->moveTo($this->directory . '/again.txt');
    }

    public function test_a_path_backed_upload_is_streamed_and_moved(): void
    {
        // Arrange
        $source = $this->directory . '/src.txt';
        file_put_contents($source, 'data');
        $file = new UploadedFile($source, 4);

        // Act
        $contents = (string) $file->getStream();
        $file->moveTo($this->directory . '/dst.txt');

        // Assert
        self::assertSame('data', $contents);
        self::assertFileExists($this->directory . '/dst.txt');
        self::assertFileDoesNotExist($source);
    }

    public function test_a_failed_upload_has_no_stream_and_cannot_be_moved(): void
    {
        // Arrange
        $file = new UploadedFile('', null, \UPLOAD_ERR_NO_FILE);

        // Act & Assert
        self::assertSame(\UPLOAD_ERR_NO_FILE, $file->getError());
        $this->expectException(RuntimeException::class);
        $file->getStream();
    }

    public function test_target_paths_with_null_bytes_are_rejected(): void
    {
        // Arrange
        $file = new UploadedFile(Stream::fromString('x'), 1);

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $file->moveTo($this->directory . "/ok.txt\0.php");
    }

    public function test_invalid_error_codes_are_rejected(): void
    {
        // Arrange

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        new UploadedFile(Stream::fromString('x'), 1, 99);
    }
}
