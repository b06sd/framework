<?php

declare(strict_types=1);

namespace Trunk\Auth\Doctor;

use Trunk\Auth\Settings\SessionSettings;
use Trunk\Database\Schema\Schema;
use Trunk\Doctor\DoctorCheck;
use Trunk\Doctor\DoctorFinding;

/**
 * `trunk doctor` also checks that the auth tables exist, not only that the database connects: signing
 * a visitor in against a table that was never migrated is otherwise a 500 on the first real request.
 */
final readonly class AuthTablesCheck implements DoctorCheck
{
    public function __construct(private Schema $schema, private SessionSettings $sessions) {}

    public function name(): string
    {
        return 'Auth tables';
    }

    public function check(): DoctorFinding
    {
        return $this->schema->hasTable($this->sessions->table)
            ? DoctorFinding::ok()
            : DoctorFinding::problem(\sprintf('"%s" does not exist yet.', $this->sessions->table), 'trunk auth:table && trunk migrate');
    }
}
