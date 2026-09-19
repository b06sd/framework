<?php

declare(strict_types=1);

namespace Trunk\Http\Upload;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Trunk\Http\Stream\Stream;

final class UploadedFile implements UploadedFileInterface
{
    private bool $moved = false;

    /**
     * @param StreamInterface|string $source path or stream holding the upload
     * @param bool $sapiUpload true only for files PHP itself received (uses move_uploaded_file, which verifies origin)
     */
    public function __construct(
        private readonly StreamInterface|string $source,
        private readonly ?int $size,
        private readonly int $error = \UPLOAD_ERR_OK,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
        private readonly bool $sapiUpload = false,
    ) {
        if ($error < \UPLOAD_ERR_OK || $error > \UPLOAD_ERR_EXTENSION) {
            throw new InvalidArgumentException('Invalid upload error status.');
        }

        if (\is_string($source) && str_contains($source, "\0")) {
            throw new InvalidArgumentException('Invalid upload path.');
        }
    }

    public function getStream(): StreamInterface
    {
        $this->assertUsable();

        return $this->source instanceof StreamInterface ? $this->source : Stream::fromFile($this->source);
    }

    public function moveTo(string $targetPath): void
    {
        $this->assertUsable();

        if ($targetPath === '' || str_contains($targetPath, "\0")) {
            throw new InvalidArgumentException('The target path is invalid.');
        }

        if (!is_dir(\dirname($targetPath)) || !is_writable(\dirname($targetPath))) {
            throw new RuntimeException('The target directory does not exist or is not writable.');
        }

        if (\is_string($this->source)) {
            $ok = $this->sapiUpload ? move_uploaded_file($this->source, $targetPath) : rename($this->source, $targetPath);

            if (!$ok) {
                throw new RuntimeException('Unable to move the uploaded file.');
            }
        } else {
            $this->copyStream($this->source, $targetPath);
        }

        $this->moved = true;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    private function assertUsable(): void
    {
        if ($this->error !== \UPLOAD_ERR_OK) {
            throw new RuntimeException('The upload failed and has no usable file.');
        }

        if ($this->moved) {
            throw new RuntimeException('The uploaded file has already been moved.');
        }
    }

    private function copyStream(StreamInterface $source, string $targetPath): void
    {
        $target = Stream::fromFile($targetPath, 'wb');

        if ($source->isSeekable()) {
            $source->rewind();
        }

        while (!$source->eof()) {
            $chunk = $source->read(8192);

            if ($chunk === '') {
                break;
            }

            $target->write($chunk);
        }

        $target->close();
    }
}
