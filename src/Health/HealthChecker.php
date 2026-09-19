<?php

declare(strict_types=1);

namespace Trunk\Health;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs every registered HealthCheck. A check that throws is `down`; one broken check never hides
 * the others. The exception is logged (class and message, for operators) and never returned.
 */
final readonly class HealthChecker
{
    /**
     * @param iterable<HealthCheck> $checks
     */
    public function __construct(private iterable $checks = [], private ?LoggerInterface $logger = null) {}

    public function run(): HealthReport
    {
        $results = [];

        foreach ($this->checks as $check) {
            try {
                $results[$check->name()] = $check->check();
            } catch (Throwable $e) {
                $results[$check->name()] = HealthResult::down($e::class);
                $this->log($check, $e);
            }
        }

        return new HealthReport($results);
    }

    private function log(HealthCheck $check, Throwable $error): void
    {
        try {
            $this->logger?->warning('Health check {check} failed.', ['check' => $check->name(), 'exception' => $error]);
        } catch (Throwable) {
            // Reporting health must not fail because logging did.
        }
    }
}
