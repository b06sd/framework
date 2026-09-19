<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Trunk\Cache\Cache;
use Trunk\Cache\Console\CacheClearCommand;
use Trunk\Cache\Console\CacheConsoleModule;
use Trunk\Cache\Store\ArrayStore;
use Trunk\Cache\Store\NullStore;
use Trunk\Cache\Store\Store;
use Trunk\Console\Input\Input;
use Trunk\Contracts\Console\CommandCollector;
use Trunk\Tests\Support\FixedClock;
use Trunk\Tests\Support\OutputCapture;

final class CacheClearCommandTest extends TestCase
{
    public function test_it_clears_whatever_psr_16_cache_is_bound(): void
    {
        // Arrange
        $clock = new FixedClock();
        $cache = new Cache(new ArrayStore($clock), $clock);
        $cache->set('a', 1);
        $capture = new OutputCapture();

        // Act
        $code = new CacheClearCommand($cache)->handle(Input::fromArgv(['trunk', 'cache:clear']), $capture->output);

        // Assert
        self::assertSame(0, $code);
        self::assertFalse($cache->has('a'));
        self::assertStringContainsString('Application cache cleared.', $capture->stdout());
    }

    public function test_a_failing_clear_is_reported_with_a_failure_exit_code(): void
    {
        // Arrange
        $failing = new class implements Store {
            public function read(string $key): ?array
            {
                return null;
            }

            public function write(string $key, mixed $value, ?int $expiresAt): bool
            {
                return true;
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function clear(): bool
            {
                return false;
            }
        };
        $capture = new OutputCapture();

        // Act
        $code = new CacheClearCommand(new Cache($failing, new FixedClock()))->handle(Input::fromArgv(['trunk', 'cache:clear']), $capture->output);

        // Assert
        self::assertSame(1, $code);
        self::assertStringContainsString('could not be cleared', $capture->stdout());
    }

    public function test_the_console_module_contributes_only_the_clear_command(): void
    {
        // Arrange
        $collector = new CommandCollector();

        // Act
        new CacheConsoleModule()->commands($collector);

        // Assert
        self::assertSame([CacheClearCommand::class], $collector->classes());
        self::assertSame('cache:clear', new CacheClearCommand(new Cache(new NullStore(), new FixedClock()))->definition()->name);
    }
}
