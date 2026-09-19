<?php

declare(strict_types=1);

namespace Trunk\Http\Stream;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

final class Stream implements StreamInterface
{
    /** @var resource|null */
    private mixed $resource;

    private readonly bool $seekable;

    private readonly bool $readable;

    private readonly bool $writable;

    /**
     * @param resource $resource
     */
    public function __construct(mixed $resource)
    {
        if (!\is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw new InvalidArgumentException('A stream resource is required.');
        }

        $meta = stream_get_meta_data($resource);
        $mode = $meta['mode'];

        $this->resource = $resource;
        $this->seekable = $meta['seekable'];
        $this->readable = str_contains($mode, 'r') || str_contains($mode, '+');
        $this->writable = strpbrk($mode, 'waxc+') !== false;
    }

    public function __toString(): string
    {
        try {
            if ($this->seekable) {
                $this->rewind();
            }

            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    public static function fromString(string $contents = ''): self
    {
        $stream = new self(self::open('php://temp', 'r+'));

        if ($contents !== '') {
            $stream->write($contents);
            $stream->rewind();
        }

        return $stream;
    }

    public static function fromFile(string $path, string $mode = 'r'): self
    {
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Invalid file path.');
        }

        return new self(self::open($path, $mode));
    }

    public function close(): void
    {
        if ($this->resource !== null) {
            fclose($this->resource);
            $this->resource = null;
        }
    }

    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;

        return $resource;
    }

    public function getSize(): ?int
    {
        if ($this->resource === null) {
            return null;
        }

        $stats = fstat($this->resource);

        return $stats === false ? null : $stats['size'];
    }

    public function tell(): int
    {
        $position = ftell($this->resource());

        return $position === false ? throw new RuntimeException('Unable to determine the stream position.') : $position;
    }

    public function eof(): bool
    {
        return $this->resource === null || feof($this->resource);
    }

    public function isSeekable(): bool
    {
        return $this->resource !== null && $this->seekable;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $resource = $this->resource();

        if (!$this->seekable) {
            throw new RuntimeException('The stream is not seekable.');
        }

        if (fseek($resource, $offset, $whence) === -1) {
            throw new RuntimeException('Unable to seek in the stream.');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->resource !== null && $this->writable;
    }

    public function write(string $string): int
    {
        $resource = $this->resource();

        if (!$this->writable) {
            throw new RuntimeException('The stream is not writable.');
        }

        $written = fwrite($resource, $string);

        return $written === false ? throw new RuntimeException('Unable to write to the stream.') : $written;
    }

    public function isReadable(): bool
    {
        return $this->resource !== null && $this->readable;
    }

    public function read(int $length): string
    {
        $resource = $this->resource();

        if (!$this->readable) {
            throw new RuntimeException('The stream is not readable.');
        }

        if ($length < 0) {
            throw new InvalidArgumentException('The length must not be negative.');
        }

        if ($length === 0) {
            return '';
        }

        $data = fread($resource, $length);

        return $data === false ? throw new RuntimeException('Unable to read from the stream.') : $data;
    }

    public function getContents(): string
    {
        $resource = $this->resource();

        if (!$this->readable) {
            throw new RuntimeException('The stream is not readable.');
        }

        $contents = stream_get_contents($resource);

        return $contents === false ? throw new RuntimeException('Unable to read the stream contents.') : $contents;
    }

    public function getMetadata(?string $key = null)
    {
        if ($this->resource === null) {
            return $key === null ? [] : null;
        }

        $meta = stream_get_meta_data($this->resource);

        return $key === null ? $meta : ($meta[$key] ?? null);
    }

    /**
     * @return resource
     */
    private function resource(): mixed
    {
        return $this->resource ?? throw new RuntimeException('The stream has been closed or detached.');
    }

    /**
     * @return resource
     */
    private static function open(string $path, string $mode): mixed
    {
        $resource = @fopen($path, $mode);

        return $resource === false ? throw new RuntimeException(\sprintf('Unable to open "%s".', $path)) : $resource;
    }
}
