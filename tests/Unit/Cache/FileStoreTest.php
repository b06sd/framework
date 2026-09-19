<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Cache;

use Trunk\Cache\Store\FileStore;
use Trunk\Cache\Store\Store;
use Trunk\Contracts\Clock;
use Trunk\Support\Directory;

final class FileStoreTest extends CacheBehaviourTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/trunk-cache-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->directory);
    }

    public function test_files_are_private_and_named_by_hash_so_a_key_never_becomes_a_path(): void
    {
        // Arrange
        $cache = $this->cache();

        // Act
        $cache->set('user.secret-token', 'abc');
        $files = $this->files();

        // Assert
        self::assertCount(1, $files);
        self::assertStringNotContainsString('secret', $files[0]);
        self::assertSame(hash('sha256', 'user.secret-token') . '.cache', basename($files[0]));
        self::assertSame('0600', substr(\sprintf('%o', fileperms($files[0])), -4));
        self::assertSame('0700', substr(\sprintf('%o', fileperms(\dirname($files[0]))), -4));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tamperedFiles(): iterable
    {
        yield 'garbage' => ['not a cache file'];
        yield 'no newline' => ['-'];
        yield 'bad expiry' => ["abc\n\"x\""];
        yield 'huge expiry' => ["99999999999999999\n\"x\""];
        yield 'invalid json' => ["-\n{broken"];
        yield 'php code' => ["-\n<?php system('id');"];
        yield 'serialized object' => ["-\nO:8:\"stdClass\":0:{}"];
        yield 'empty file' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tamperedFiles')]
    public function test_a_tampered_file_is_a_miss_and_is_removed(string $contents): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->set('victim', 'original');
        $file = $this->files()[0];
        file_put_contents($file, $contents);

        // Act
        $has = $cache->has('victim');
        $value = $cache->get('victim', 'default');

        // Assert
        self::assertFalse($has);
        self::assertSame('default', $value);
        self::assertFileDoesNotExist($file);
    }

    public function test_an_expired_file_is_deleted_when_it_is_read(): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->set('old', 'x', 10);
        $this->clock->advance(10);

        // Act
        $has = $cache->has('old');

        // Assert
        self::assertFalse($has);
        self::assertSame([], $this->files());
    }

    public function test_clear_only_removes_the_stores_own_shards(): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->set('a', 1);
        mkdir($this->directory . '/not-a-shard');
        file_put_contents($this->directory . '/not-a-shard/keep.txt', 'keep');
        file_put_contents($this->directory . '/notes.txt', 'keep');

        // Act
        $cache->clear();

        // Assert
        self::assertSame([], $this->files());
        self::assertFileExists($this->directory . '/not-a-shard/keep.txt');
        self::assertFileExists($this->directory . '/notes.txt');
    }

    public function test_a_second_store_instance_reads_what_the_first_wrote(): void
    {
        // Arrange
        $this->cache()->set('shared', ['a' => 1], 100);

        // Act
        $value = $this->cache()->get('shared');

        // Assert
        self::assertSame(['a' => 1], $value);
    }

    protected function store(Clock $clock): Store
    {
        return new FileStore($this->directory, $clock);
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        return glob($this->directory . '/*/*.cache') ?: [];
    }
}
