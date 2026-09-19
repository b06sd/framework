<?php

declare(strict_types=1);

namespace Trunk\Http\Stream;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Trunk\Error\ErrorCode;
use Trunk\Http\Exception\HttpException;

/**
 * Wraps a request body so that reading more than `$limit` bytes raises 413, whatever the request
 * claimed in its headers (a lying Content-Length or a chunked body cannot bypass the limit). Nothing
 * is read until the application asks for it.
 */
final class BoundedStream implements StreamInterface
{
    private int $consumed = 0;

    public function __construct(private readonly StreamInterface $inner, private readonly int $limit) {}

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->inner->rewind();
        $this->consumed = 0;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('The request body is read-only.');
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    public function read(int $length): string
    {
        $data = $this->inner->read(min($length, $this->limit - $this->consumed + 1));
        $this->account(\strlen($data));

        return $data;
    }

    public function getContents(): string
    {
        $contents = '';

        while (!$this->inner->eof()) {
            $chunk = $this->read(8192);

            if ($chunk === '') {
                break;
            }

            $contents .= $chunk;
        }

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }

    private function account(int $bytes): void
    {
        $this->consumed += $bytes;

        if ($this->consumed > $this->limit) {
            throw new HttpException(413, 'The request body exceeds the configured limit.', code: ErrorCode::PayloadTooLarge->value);
        }
    }
}
