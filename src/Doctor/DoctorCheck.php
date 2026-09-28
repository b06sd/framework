<?php

declare(strict_types=1);

namespace Trunk\Doctor;

/**
 * A runtime readiness check `trunk doctor` runs against the real application (a booted container,
 * a real database connection) rather than just its files and config. Register implementations with
 * the tag `trunk.doctor_check`. check() should be quick; throw or return a problem on failure.
 *
 * Unlike `HealthCheck` (which a deployed application exposes at `/health/ready` for a load balancer),
 * a doctor check runs only when a developer asks, and its message may be as specific as it likes
 * (`HealthCheck` details are for logs only; a doctor message is read directly by whoever ran the
 * command).
 *
 * @api
 */
interface DoctorCheck
{
    public function name(): string;

    public function check(): DoctorFinding;
}
