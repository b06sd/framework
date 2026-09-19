<?php

declare(strict_types=1);

namespace Trunk\Health;

final readonly class HealthReport
{
    /**
     * @param array<string, HealthResult> $results check name => result
     */
    public function __construct(public array $results) {}

    public function up(): bool
    {
        foreach ($this->results as $result) {
            if (!$result->up) {
                return false;
            }
        }

        return true;
    }
}
