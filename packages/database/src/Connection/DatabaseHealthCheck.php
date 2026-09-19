<?php

declare(strict_types=1);

namespace Trunk\Database\Connection;

use Throwable;
use Trunk\Health\HealthCheck;
use Trunk\Health\HealthResult;

/**
 * Readiness of the default database connection: `SELECT 1`.
 */
final readonly class DatabaseHealthCheck implements HealthCheck
{
    public function __construct(private ConnectionManager $connections) {}

    public function name(): string
    {
        return 'database';
    }

    public function check(): HealthResult
    {
        try {
            return $this->connections->connection()->scalar('SELECT 1') !== null ? HealthResult::up() : HealthResult::down('no result');
        } catch (Throwable $e) {
            return HealthResult::down($e::class);
        }
    }
}
