<?php

declare(strict_types=1);

namespace Trunk\Storage;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\GeneratedExtensionToMimeTypeMap;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Trunk\Foundation\Configuration;
use Trunk\Storage\Exception\StorageException;

/**
 * Files on named disks (config/storage.php): the local disk, S3 or anything S3-compatible (MinIO,
 * Cloudflare R2), or memory for tests. `disk()` gives Flysystem's own `FilesystemOperator`:
 *
 *   $storage->disk()->write('reports/2026-10.csv', $csv);
 *   $stream = $storage->disk('s3')->readStream($path);
 *
 * `storeUpload()` keeps an uploaded file safely: a random name, an extension from what the file
 * really contains (not what the client claimed), optionally only some types, streamed.
 *
 * @api
 */
final class Storage
{
    private const int SNIFF_BYTES = 4096;

    /** @var array<string, FilesystemOperator> */
    private array $disks = [];

    /** @internal wired by StorageModule */
    public function __construct(private readonly Configuration $configuration, private readonly DiskFactory $factory = new DiskFactory()) {}

    /**
     * A disk by name, or the default disk (`storage.default`).
     *
     * @throws StorageException when the disk is not configured or its settings are incomplete
     */
    public function disk(?string $name = null): FilesystemOperator
    {
        $name ??= $this->configuration->string('storage.default');

        if (isset($this->disks[$name])) {
            return $this->disks[$name];
        }

        $disks = $this->configuration->has('storage.disks') ? $this->configuration->array('storage.disks') : [];
        $settings = $disks[$name] ?? null;

        if (!\is_array($settings)) {
            throw new StorageException(\sprintf('There is no disk "%s" in config/storage.php (storage.disks).', $name));
        }

        return $this->disks[$name] = $this->factory->make($name, $settings);
    }

    /**
     * Stores an uploaded file under `$directory` with a new random name and returns its path on the
     * disk. The client's file name is never used, and the extension comes from the file's contents.
     *
     * @param list<string> $types accepted media types, e.g. ['image/png', 'image/jpeg', 'application/pdf'] or ['image/*']; empty accepts any
     *
     * @throws StorageException when the upload failed, its type is not accepted, or it could not be written
     */
    public function storeUpload(UploadedFileInterface $file, string $directory = '', array $types = [], ?string $disk = null): string
    {
        if ($file->getError() !== \UPLOAD_ERR_OK) {
            throw new StorageException('The upload did not arrive complete (upload error ' . $file->getError() . ').');
        }

        if ($directory !== '' && preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#D', $directory) !== 1) {
            throw new StorageException('The upload directory must be plain folder names separated by "/" (letters, digits, _ and -).');
        }

        $stream = $file->getStream();
        $head = $stream->read(self::SNIFF_BYTES);
        $type = new FinfoMimeTypeDetector()->detectMimeTypeFromBuffer($head) ?? 'application/octet-stream';

        if ($types !== [] && !self::accepts($types, $type)) {
            throw new StorageException(\sprintf('Files of type %s are not accepted here.', $type));
        }

        $extension = new GeneratedExtensionToMimeTypeMap()->lookupExtension($type) ?? 'bin';
        $path = ($directory === '' ? '' : $directory . '/') . bin2hex(random_bytes(16)) . '.' . $extension;
        $resource = $this->resource($stream, $head);

        try {
            $this->disk($disk)->writeStream($path, $resource);
        } catch (FilesystemException $e) {
            throw new StorageException('The upload could not be stored.', 0, $e);
        } finally {
            if (\is_resource($resource)) {
                fclose($resource);
            }
        }

        return $path;
    }

    /**
     * @param list<string> $types
     */
    private static function accepts(array $types, string $type): bool
    {
        foreach ($types as $accepted) {
            if ($accepted === $type || (str_ends_with($accepted, '/*') && str_starts_with($type, substr($accepted, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The whole upload as a stream resource, the sniffed head included, without loading it into memory:
     * a file-backed stream is handed over as it is; anything else is copied to a temporary stream.
     *
     * @return resource
     */
    private function resource(StreamInterface $stream, string $head): mixed
    {
        if ($stream->isSeekable() && $stream->getMetadata('uri') !== null) {
            $stream->rewind();
            $resource = $stream->detach();

            return \is_resource($resource) ? $resource : throw new StorageException('The upload could not be read.');
        }

        $copy = fopen('php://temp', 'r+b');

        if ($copy === false) {
            throw new StorageException('The upload could not be buffered.');
        }

        if ($stream->isSeekable()) {
            $stream->rewind();
        } else {
            fwrite($copy, $head);
        }

        while (!$stream->eof()) {
            fwrite($copy, $stream->read(65536));
        }

        rewind($copy);

        return $copy;
    }
}
