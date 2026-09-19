<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Foundation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Runtime;

final class EnvironmentTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, Environment}>
     */
    public static function values(): iterable
    {
        yield 'local' => ['local', Environment::Local];
        yield 'testing' => ['testing', Environment::Testing];
        yield 'case and whitespace' => [' LOCAL ', Environment::Local];
        yield 'unknown falls back to production' => ['staging', Environment::Production];
        yield 'null falls back to production' => [null, Environment::Production];
    }

    #[DataProvider('values')]
    public function test_environment_is_parsed_with_production_as_the_safe_default(?string $input, Environment $expected): void
    {
        // Arrange

        // Act
        $result = Environment::fromString($input);

        // Assert
        self::assertSame($expected, $result);
    }

    public function test_debug_is_forced_off_in_production(): void
    {
        // Arrange

        // Act
        $runtime = new Runtime(Environment::Production, true, '/app');

        // Assert
        self::assertFalse($runtime->debug);
    }

    public function test_debug_is_honoured_outside_production(): void
    {
        // Arrange

        // Act
        $runtime = new Runtime(Environment::Local, true, '/app');

        // Assert
        self::assertTrue($runtime->debug);
    }
}
