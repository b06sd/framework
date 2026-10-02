<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Storage;

use League\Flysystem\FilesystemException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Http\Factory\HttpFactory;
use Trunk\Http\Stream\Stream;
use Trunk\Storage\Exception\StorageException;
use Trunk\Storage\Storage;
use Trunk\Storage\StorageModule;
use Trunk\Support\Directory;

final class StorageTest extends TestCase
{
    private const string PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/trunk-storage-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->root);
    }

    public function test_the_local_disk_keeps_files_private_and_refuses_paths_that_climb(): void
    {
        // Arrange
        $disk = $this->storage()->disk();

        // Act
        $disk->write('reports/2026/october.csv', "a,b\n1,2\n");

        // Assert
        self::assertSame("a,b\n1,2\n", $disk->read('reports/2026/october.csv'));
        self::assertSame('0640', substr(\sprintf('%o', fileperms($this->root . '/app/reports/2026/october.csv')), -4));
        self::assertSame('0750', substr(\sprintf('%o', fileperms($this->root . '/app/reports/2026')), -4));

        foreach (['../escape.txt', 'reports/../../escape.txt', 'reports/../october.csv'] as $path) {
            try {
                $disk->write($path, 'x');
                self::fail($path . ' should be refused');
            } catch (FilesystemException|RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertFileDoesNotExist($this->root . '/escape.txt');
    }

    public function test_a_symbolic_link_inside_the_disk_is_refused(): void
    {
        // Arrange: a link that would lead out of the disk
        $disk = $this->storage()->disk();
        $disk->write('placeholder.txt', 'x');
        symlink('/etc', $this->root . '/app/etc');

        // Act & Assert
        $this->expectException(FilesystemException::class);
        iterator_to_array($disk->listContents('', true));
    }

    public function test_disks_by_name_and_clear_errors_for_missing_or_incomplete_ones(): void
    {
        // Arrange
        $storage = $this->storage();

        // Act & Assert
        $storage->disk('memory')->write('a.txt', 'kept in memory');
        self::assertSame('kept in memory', $storage->disk('memory')->read('a.txt'));
        self::assertSame($storage->disk('memory'), $storage->disk('memory'), 'one instance per disk');

        foreach (['nowhere' => 'There is no disk "nowhere"', 's3' => 'storage.disks.s3 needs a bucket and a region'] as $disk => $message) {
            try {
                $storage->disk($disk);
                self::fail($disk . ' should be refused');
            } catch (StorageException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_an_upload_gets_a_random_name_and_the_extension_its_contents_deserve(): void
    {
        // Arrange: a real PNG the client called evil.php, and an HTML page the client called photo.jpg
        $storage = $this->storage();
        $png = new HttpFactory()->createUploadedFile(Stream::fromString(self::PNG), \strlen(self::PNG), \UPLOAD_ERR_OK, 'evil.php', 'application/x-php');
        $html = new HttpFactory()->createUploadedFile(Stream::fromString('<!doctype html><html><script>alert(1)</script></html>'), null, \UPLOAD_ERR_OK, 'photo.jpg', 'image/jpeg');

        // Act
        $stored = $storage->storeUpload($png, 'avatars', ['image/png', 'image/jpeg']);
        $any = $storage->storeUpload(new HttpFactory()->createUploadedFile(Stream::fromString('<!doctype html><html></html>')), 'inbox');

        // Assert
        self::assertMatchesRegularExpression('#^avatars/[0-9a-f]{32}\.png$#D', $stored);
        self::assertSame(self::PNG, $storage->disk()->read($stored), 'stored byte for byte');
        self::assertMatchesRegularExpression('#^inbox/[0-9a-f]{32}\.html$#D', $any, 'the extension follows the contents, never the client');

        try {
            $storage->storeUpload($html, 'avatars', ['image/*']);
            self::fail('An HTML page posing as a JPEG must be refused.');
        } catch (StorageException $e) {
            self::assertStringContainsString('text/html are not accepted', $e->getMessage());
        }
    }

    public function test_bad_directories_and_failed_uploads_are_refused(): void
    {
        // Arrange
        $storage = $this->storage();
        $file = static fn(int $error = \UPLOAD_ERR_OK) => new HttpFactory()->createUploadedFile(Stream::fromString(self::PNG), null, $error);

        // Act & Assert
        foreach (['../up', '/absolute', 'a/../b', 'with space', 'a//b'] as $directory) {
            try {
                $storage->storeUpload($file(), $directory);
                self::fail($directory . ' should be refused');
            } catch (StorageException $e) {
                self::assertStringContainsString('plain folder names', $e->getMessage());
            }
        }

        $this->expectException(StorageException::class);
        $storage->storeUpload($file(\UPLOAD_ERR_PARTIAL));
    }

    public function test_a_large_upload_is_streamed_not_loaded(): void
    {
        // Arrange: a 24 MB file on disk, the way PHP receives an upload
        $path = $this->root . '/upload.bin';
        $handle = fopen($path, 'wb');
        self::assertNotFalse($handle);
        fwrite($handle, self::PNG);
        ftruncate($handle, 24 * 1024 * 1024);
        fclose($handle);
        $upload = new HttpFactory()->createUploadedFile(Stream::fromFile($path), 24 * 1024 * 1024);
        $before = memory_get_usage();

        // Act
        $stored = $this->storage()->storeUpload($upload, 'big');

        // Assert
        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $before);
        self::assertSame(24 * 1024 * 1024, filesize($this->root . '/app/' . $stored));
    }

    public function test_the_build_checks_the_disks(): void
    {
        // Arrange
        $runtime = new Runtime(Environment::Production, false, $this->root);
        $cases = [
            'unknown driver' => [['default' => 'local', 'disks' => ['local' => ['driver' => 'ftp']]], 'driver must be local, s3 or memory'],
            'missing default' => [['default' => 'nope', 'disks' => ['local' => ['driver' => 'local', 'root' => '/tmp']]], 'storage.default (STORAGE_DISK) must be one of the disks'],
            'relative root' => [['default' => 'local', 'disks' => ['local' => ['driver' => 'local', 'root' => 'storage']]], 'root must be an absolute directory path'],
            'incomplete default s3' => [['default' => 's3', 'disks' => ['s3' => ['driver' => 's3', 'bucket' => '', 'region' => 'eu-west-1']]], 'needs a bucket and a region'],
        ];

        foreach ($cases as $case => [$storage, $message]) {
            // Act & Assert
            try {
                new StorageModule()->plan(new BuildContext(new ModuleManifest([]), $runtime, new Configuration(['storage' => $storage])));
                self::fail($case . ' should fail the build');
            } catch (CompilationException $e) {
                self::assertStringContainsString($message, implode("\n", $e->errors), $case);
            }
        }

        // An s3 entry nobody uses yet (the template's) does not fail the build.
        new StorageModule()->plan(new BuildContext(new ModuleManifest([]), $runtime, new Configuration(['storage' => ['default' => 'local', 'disks' => ['local' => ['driver' => 'local', 'root' => '/tmp'], 's3' => ['driver' => 's3', 'bucket' => '', 'region' => '']]]])));
    }

    private function storage(): Storage
    {
        return new Storage(new Configuration(['storage' => [
            'default' => 'local',
            'disks' => [
                'local' => ['driver' => 'local', 'root' => $this->root . '/app'],
                'memory' => ['driver' => 'memory'],
                's3' => ['driver' => 's3', 'bucket' => '', 'region' => 'us-east-1'],
            ],
        ]]));
    }
}
