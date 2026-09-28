<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\RateLimit;

use PHPUnit\Framework\TestCase;
use Trunk\Http\Exception\HttpException;
use Trunk\RateLimit\RateLimiter;
use Trunk\Tests\Support\DatabaseHarness;
use Trunk\Tests\Support\FixedClock;

final class RateLimiterTest extends TestCase
{
    private RateLimiter $limiter;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $connection = new DatabaseHarness()->sqlite();
        $connection->execute('CREATE TABLE trunk_rate_limits (key_hash VARCHAR(64) PRIMARY KEY, attempts INTEGER NOT NULL, window_start INTEGER NOT NULL)');
        $this->clock = new FixedClock();
        $this->limiter = new RateLimiter($connection, 'trunk_rate_limits', $this->clock);
    }

    public function test_a_key_below_its_limit_is_never_refused(): void
    {
        // Act & Assert: no exception
        for ($i = 0; $i < 5; ++$i) {
            $this->limiter->assertBelow('a', 5, 60);
            $this->limiter->hit('a', 60);
        }

        $this->expectNotToPerformAssertions();
    }

    public function test_the_limit_throws_a_429_with_retry_after_once_reached(): void
    {
        // Arrange
        for ($i = 0; $i < 3; ++$i) {
            $this->limiter->assertBelow('b', 3, 60);
            $this->limiter->hit('b', 60);
        }

        // Act & Assert
        try {
            $this->limiter->assertBelow('b', 3, 60);
            self::fail('expected an HttpException');
        } catch (HttpException $e) {
            self::assertSame(429, $e->statusCode);
            self::assertArrayHasKey('Retry-After', $e->headers);
            self::assertGreaterThanOrEqual(1, (int) $e->headers['Retry-After']);
            self::assertLessThanOrEqual(60, (int) $e->headers['Retry-After']);
        }
    }

    public function test_different_keys_never_share_a_counter(): void
    {
        // Arrange
        $this->limiter->hit('one', 60);
        $this->limiter->hit('one', 60);

        // Act & Assert: 'two' is untouched
        $this->limiter->assertBelow('two', 1, 60);
        $this->expectNotToPerformAssertions();
    }

    public function test_a_counter_resets_once_its_window_has_passed(): void
    {
        // Arrange
        $this->limiter->hit('c', 60);
        $this->limiter->hit('c', 60);

        // Act
        $this->clock->advance(61);

        // Assert: the old window no longer counts, so a fresh hit starts a new one
        $this->limiter->assertBelow('c', 2, 60);
        $this->limiter->hit('c', 60);
        $this->limiter->assertBelow('c', 2, 60);
        $this->expectNotToPerformAssertions();
    }

    public function test_clear_removes_a_counter_immediately(): void
    {
        // Arrange
        for ($i = 0; $i < 2; ++$i) {
            $this->limiter->hit('d', 60);
        }

        // Act
        $this->limiter->clear('d');

        // Assert
        $this->limiter->assertBelow('d', 2, 60);
        $this->expectNotToPerformAssertions();
    }

    public function test_prune_removes_only_windows_older_than_the_given_age(): void
    {
        // Arrange
        $this->limiter->hit('old', 60);
        $this->clock->advance(120);
        $this->limiter->hit('new', 60);

        // Act
        $removed = $this->limiter->prune(60);

        // Assert
        self::assertSame(1, $removed);
    }
}
