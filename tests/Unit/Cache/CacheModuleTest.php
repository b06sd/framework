<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Predis\Client;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use Trunk\Cache\CacheModule;
use Trunk\Cache\Exception\CacheException;
use Trunk\Cache\Store\RedisStore;
use Trunk\Cache\Store\Store;
use Trunk\Container\ContainerBuilder;
use Trunk\Support\Directory;
use Trunk\Tests\Support\ContainerModes;

final class CacheModuleTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-cache-module-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->directory);
    }

    public function test_the_cache_is_available_through_the_psr_16_interface_in_development_and_compiled_containers(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(
            static fn(ContainerBuilder $b) => new CacheModule()->register($b),
            ['cache' => ['driver' => 'file', 'path' => $this->directory, 'prefix' => 'app.']],
        );

        foreach ($containers as $mode => $container) {
            // Act
            $cache = $container->get(CacheInterface::class);

            // Assert
            self::assertInstanceOf(CacheInterface::class, $cache, $mode);
            self::assertTrue($cache->set('module', ['works' => true], 60), $mode);
            self::assertSame(['works' => true], $cache->get('module'), $mode);
            self::assertSame($cache, $container->get(CacheInterface::class), $mode);
        }

        self::assertCount(1, glob($this->directory . '/*/*.cache') ?: []);
    }

    public function test_the_driver_is_chosen_from_configuration(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(
            static fn(ContainerBuilder $b) => new CacheModule()->register($b),
            ['cache' => ['driver' => 'null', 'path' => $this->directory, 'prefix' => '']],
        );

        foreach ($containers as $mode => $container) {
            // Act
            $cache = $container->get(CacheInterface::class);
            self::assertInstanceOf(CacheInterface::class, $cache, $mode);
            $cache->set('gone', 1);

            // Assert
            self::assertFalse($cache->has('gone'), $mode);
            self::assertInstanceOf(Store::class, $container->get(Store::class), $mode);
        }
    }

    public function test_a_project_without_a_redis_config_block_still_works_on_every_other_driver(): void
    {
        // Arrange: config/cache.php written before the redis store existed has no `redis` key at all.
        $containers = new ContainerModes()->both(
            static fn(ContainerBuilder $b) => new CacheModule()->register($b),
            ['cache' => ['driver' => 'file', 'path' => $this->directory, 'prefix' => '']],
        );

        foreach ($containers as $mode => $container) {
            // Act
            $cache = $container->get(CacheInterface::class);

            // Assert
            self::assertInstanceOf(CacheInterface::class, $cache, $mode);
            self::assertTrue($cache->set('fine', 1), $mode);
        }
    }

    public function test_the_redis_driver_is_wired_through_the_container_from_its_own_config_block(): void
    {
        // Arrange
        if (!class_exists(Client::class) || !@fsockopen('127.0.0.1', 6379, timeout: 0.2)) {
            self::markTestSkipped('No Redis server reachable at 127.0.0.1:6379.');
        }

        new Client(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379, 'database' => 15])->flushdb();
        $containers = new ContainerModes()->both(
            static fn(ContainerBuilder $b) => new CacheModule()->register($b),
            ['cache' => ['driver' => 'redis', 'path' => $this->directory, 'prefix' => 'app.', 'redis' => ['host' => '127.0.0.1', 'port' => 6379, 'password' => '', 'database' => 15]]],
        );

        foreach ($containers as $mode => $container) {
            // Act
            $cache = $container->get(CacheInterface::class);

            // Assert
            self::assertInstanceOf(CacheInterface::class, $cache, $mode);
            self::assertInstanceOf(RedisStore::class, $container->get(Store::class), $mode);
            self::assertTrue($cache->set('module', ['works' => true], 60), $mode);
            self::assertSame(['works' => true], $cache->get('module'), $mode);
        }
    }

    public function test_an_unknown_driver_fails_with_a_message_that_names_the_setting(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(
            static fn(ContainerBuilder $b) => new CacheModule()->register($b),
            ['cache' => ['driver' => 'memcached', 'path' => $this->directory, 'prefix' => '']],
        );

        foreach ($containers as $mode => $container) {
            // Act & Assert
            try {
                $container->get(CacheInterface::class);
                self::fail('Expected a CacheException in ' . $mode);
            } catch (Throwable $e) {
                self::assertInstanceOf(CacheException::class, $e, $mode);
                self::assertStringContainsString('CACHE_DRIVER', $e->getMessage(), $mode);
            }
        }
    }
}
