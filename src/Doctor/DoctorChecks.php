<?php

declare(strict_types=1);

namespace Trunk\Doctor;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs every registered DoctorCheck against the real, booted application. A check that throws is a
 * problem; one broken check never hides the others. Mirrors `Trunk\Health\HealthChecker`, for
 * `trunk doctor` instead of a deployed `/health/ready` endpoint.
 */
final readonly class DoctorChecks
{
    /**
     * @param iterable<DoctorCheck> $checks
     */
    public function __construct(private iterable $checks = [], private ?LoggerInterface $logger = null) {}

    /**
     * @return array<string, DoctorFinding>
     */
    public function run(): array
    {
        $findings = [];

        foreach ($this->checks as $check) {
            try {
                $findings[$check->name()] = $check->check();
            } catch (Throwable $e) {
                $findings[$check->name()] = DoctorFinding::problem($e->getMessage());
                $this->log($check, $e);
            }
        }

        return $findings;
    }

    private function log(DoctorCheck $check, Throwable $error): void
    {
        try {
            $this->logger?->warning('Doctor check {check} failed.', ['check' => $check->name(), 'exception' => $error]);
        } catch (Throwable) {
            // Reporting a problem must not fail because logging did.
        }
    }
}
