<?php

declare(strict_types=1);

namespace Trunk\Queue\Doctor;

use Trunk\Database\Schema\Schema;
use Trunk\Doctor\DoctorCheck;
use Trunk\Doctor\DoctorFinding;

/**
 * `trunk doctor` also checks that the queue's own tables exist, not only that the database connects:
 * dispatching to a table that was never migrated is otherwise a 500 on the first real request.
 */
final readonly class QueueTablesCheck implements DoctorCheck
{
    public function __construct(private Schema $schema, private string $table) {}

    public function name(): string
    {
        return 'Queue tables';
    }

    public function check(): DoctorFinding
    {
        return $this->schema->hasTable($this->table)
            ? DoctorFinding::ok()
            : DoctorFinding::problem(\sprintf('"%s" does not exist yet.', $this->table), 'trunk queue:table && trunk migrate');
    }
}
