<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Configuration;
use Trunk\Http\Factory\HttpFactory;
use Trunk\Http\Stream\Stream;
use Trunk\Storage\Storage;

/**
 * A real S3 (or S3-compatible, e.g. MinIO) bucket, only when TRUNK_TEST_S3_ENDPOINT, _BUCKET, _KEY,
 * _SECRET (and optional _REGION) are set. Everything is written under a fresh trunk-test-* prefix and
 * removed afterwards.
 */
final class S3DiskTest extends TestCase
{
    public function test_files_round_trip_stream_stay_private_and_get_temporary_urls(): void
    {
        // Arrange
        $endpoint = getenv('TRUNK_TEST_S3_ENDPOINT');
        $bucket = getenv('TRUNK_TEST_S3_BUCKET');

        if ($endpoint === false || $bucket === false) {
            self::markTestSkipped('Set TRUNK_TEST_S3_ENDPOINT, _BUCKET, _KEY and _SECRET to run this test.');
        }

        $prefix = 'trunk-test-' . bin2hex(random_bytes(4));
        $storage = new Storage(new Configuration(['storage' => ['default' => 's3', 'disks' => ['s3' => [
            'driver' => 's3',
            'bucket' => $bucket,
            'region' => getenv('TRUNK_TEST_S3_REGION') ?: 'us-east-1',
            'key' => (string) getenv('TRUNK_TEST_S3_KEY'),
            'secret' => (string) getenv('TRUNK_TEST_S3_SECRET'),
            'endpoint' => $endpoint,
            'path_style' => true,
            'prefix' => $prefix,
        ]]]]));
        $disk = $storage->disk();
        $png = "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64);

        try {
            // Act
            $disk->write('notes/hello.txt', 'hello from trunk');
            $stream = $disk->readStream('notes/hello.txt');
            $streamed = stream_get_contents($stream);
            $stored = $storage->storeUpload(new HttpFactory()->createUploadedFile(Stream::fromString($png), null, \UPLOAD_ERR_OK, 'x.php'), 'uploads', ['image/png']);
            $url = $disk->temporaryUrl('notes/hello.txt', new DateTimeImmutable('+5 minutes'));
            $listed = array_map(static fn($item): string => $item->path(), iterator_to_array($disk->listContents('', true)->filter(static fn($item): bool => $item->isFile())));

            // Assert
            self::assertSame('hello from trunk', $disk->read('notes/hello.txt'));
            self::assertSame('hello from trunk', $streamed);
            self::assertSame('private', $disk->visibility('notes/hello.txt'));
            self::assertMatchesRegularExpression('#^uploads/[0-9a-f]{32}\.png$#D', $stored);
            self::assertSame($png, $disk->read($stored));
            self::assertStringContainsString('X-Amz-Signature=', $url);
            self::assertEqualsCanonicalizing(['notes/hello.txt', $stored], array_values($listed));
        } finally {
            $disk->deleteDirectory('');
        }

        self::assertSame([], iterator_to_array($disk->listContents('', true)));
    }
}
