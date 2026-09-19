<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use Trunk\Cache\CacheModule;
use Trunk\Cache\Exception\CacheException;
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

    public function test_an_unknown_driver_fails_with_a_message_that_names_the_setting(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(
            static fn(ContainerBuilder $b) => new CacheModule()->register($b),
            ['cache' => ['driver' => 'redis', 'path' => $this->directory, 'prefix' => '']],
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
