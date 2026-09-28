<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Doctor;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Trunk\Doctor\DoctorCheck;
use Trunk\Doctor\DoctorChecks;
use Trunk\Doctor\DoctorFinding;
use Trunk\Tests\Support\RecordingLogger;

final class DoctorChecksTest extends TestCase
{
    public function test_a_throwing_check_is_a_problem_without_hiding_the_others_and_never_returns_the_exception_message(): void
    {
        // Arrange
        $logger = new RecordingLogger();
        $checks = new DoctorChecks([$this->check('a', DoctorFinding::ok()), $this->check('b', null, true), $this->check('c', DoctorFinding::problem('manual', 'fix it'))], $logger);

        // Act
        $findings = $checks->run();

        // Assert
        self::assertSame(['a' => true, 'b' => false, 'c' => false], array_map(static fn(DoctorFinding $f): bool => $f->ok, $findings));
        self::assertSame('boom', $findings['b']->message);
        self::assertSame('manual', $findings['c']->message);
        self::assertSame('fix it', $findings['c']->fix);
        self::assertSame('warning', $logger->records[0][0]);
        self::assertSame([], new DoctorChecks()->run(), 'no checks registered means nothing to report');
    }

    private function check(string $name, ?DoctorFinding $result, bool $throws = false): DoctorCheck
    {
        return new class ($name, $result, $throws) implements DoctorCheck {
            public function __construct(private string $name, private ?DoctorFinding $result, private bool $throws) {}

            public function name(): string
            {
                return $this->name;
            }

            public function check(): DoctorFinding
            {
                return $this->throws ? throw new RuntimeException('boom') : ($this->result ?? DoctorFinding::ok());
            }
        };
    }
}
