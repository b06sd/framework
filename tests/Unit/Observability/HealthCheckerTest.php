<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Observability;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Trunk\Health\HealthCheck;
use Trunk\Health\HealthChecker;
use Trunk\Health\HealthResult;
use Trunk\Tests\Support\RecordingLogger;

final class HealthCheckerTest extends TestCase
{
    public function test_a_throwing_check_is_down_without_hiding_the_others_and_never_returns_the_exception_message(): void
    {
        // Arrange
        $logger = new RecordingLogger();
        $checker = new HealthChecker([$this->check('a', HealthResult::up()), $this->check('b', null, true), $this->check('c', HealthResult::down('manual'))], $logger);

        // Act
        $report = $checker->run();

        // Assert
        self::assertFalse($report->up());
        self::assertSame(['a' => true, 'b' => false, 'c' => false], array_map(static fn(HealthResult $r): bool => $r->up, $report->results));
        self::assertSame(RuntimeException::class, $report->results['b']->detail);
        self::assertSame('warning', $logger->records[0][0]);
        self::assertTrue(new HealthChecker([$this->check('only', HealthResult::up())])->run()->up());
        self::assertTrue(new HealthChecker()->run()->up(), 'no checks registered means ready');
    }

    private function check(string $name, ?HealthResult $result, bool $throws = false): HealthCheck
    {
        return new class ($name, $result, $throws) implements HealthCheck {
            public function __construct(private string $name, private ?HealthResult $result, private bool $throws) {}

            public function name(): string
            {
                return $this->name;
            }

            public function check(): HealthResult
            {
                return $this->throws ? throw new RuntimeException('secret-connection-string') : ($this->result ?? HealthResult::up());
            }
        };
    }
}
