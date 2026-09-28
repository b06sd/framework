<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Schedule;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Schedule\Frequency;

final class FrequencyTest extends TestCase
{
    public function test_every_minutes_is_due_only_on_matching_minute_boundaries(): void
    {
        // Arrange
        $frequency = Frequency::everyMinutes(5);

        // Act & Assert: 1970-01-01 00:00 UTC is minute 0 (due), 00:05 is minute 5 (due), 00:03 is not
        self::assertTrue($frequency->isDue(new DateTimeImmutable('@0')));
        self::assertTrue($frequency->isDue(new DateTimeImmutable('@' . (5 * 60))));
        self::assertFalse($frequency->isDue(new DateTimeImmutable('@' . (3 * 60))));
    }

    public function test_every_minute_is_always_due(): void
    {
        // Arrange
        $frequency = Frequency::everyMinutes(1);

        // Act & Assert
        self::assertTrue($frequency->isDue(new DateTimeImmutable('@12345')));
        self::assertSame('every minute', $frequency->describe());
    }

    public function test_hourly_is_due_only_at_the_top_of_the_hour(): void
    {
        // Arrange
        $frequency = Frequency::hourly();

        // Act & Assert
        self::assertTrue($frequency->isDue(new DateTimeImmutable('2026-01-01 03:00:59 UTC')));
        self::assertFalse($frequency->isDue(new DateTimeImmutable('2026-01-01 03:01:00 UTC')));
        self::assertSame('hourly, on the hour', $frequency->describe());
    }

    public function test_daily_is_due_only_at_its_time_of_day(): void
    {
        // Arrange
        $frequency = Frequency::daily('02:30');

        // Act & Assert
        self::assertTrue($frequency->isDue(new DateTimeImmutable('2026-06-15 02:30:00 UTC')));
        self::assertFalse($frequency->isDue(new DateTimeImmutable('2026-06-15 02:31:00 UTC')));
        self::assertTrue($frequency->isDue(new DateTimeImmutable('2026-06-16 02:30:00 UTC')), 'daily does not care which day');
        self::assertSame('daily at 02:30 UTC', $frequency->describe());
    }

    public function test_weekly_is_due_only_on_its_day_and_time(): void
    {
        // Arrange: 2026-06-15 is a Monday
        $frequency = Frequency::weekly(1, '09:00');

        // Act & Assert
        self::assertTrue($frequency->isDue(new DateTimeImmutable('2026-06-15 09:00:00 UTC')));
        self::assertFalse($frequency->isDue(new DateTimeImmutable('2026-06-16 09:00:00 UTC')), 'wrong day');
        self::assertFalse($frequency->isDue(new DateTimeImmutable('2026-06-15 09:01:00 UTC')), 'wrong time');
        self::assertSame('Monday at 09:00 UTC', $frequency->describe());
    }

    /**
     * @return iterable<string, array{0: callable(): Frequency}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'everyMinutes zero' => [static fn() => Frequency::everyMinutes(0)];
        yield 'everyMinutes negative' => [static fn() => Frequency::everyMinutes(-1)];
        yield 'daily bad format' => [static fn() => Frequency::daily('9:30')];
        yield 'daily bad hour' => [static fn() => Frequency::daily('24:00')];
        yield 'daily bad minute' => [static fn() => Frequency::daily('12:60')];
        yield 'weekly day too low' => [static fn() => Frequency::weekly(-1, '00:00')];
        yield 'weekly day too high' => [static fn() => Frequency::weekly(7, '00:00')];
    }

    #[DataProvider('invalidInputs')]
    public function test_malformed_input_is_refused_immediately(callable $make): void
    {
        $this->expectException(InvalidArgumentException::class);
        $make();
    }
}
