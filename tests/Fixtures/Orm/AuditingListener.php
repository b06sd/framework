<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

use RuntimeException;
use Trunk\Database\Connection\Connection;
use Trunk\Orm\UnitOfWork\ChangeListener;
use Trunk\Orm\UnitOfWork\Changes;

/**
 * An audit trail the way a project would write one: a row per change, through the connection, so it
 * commits with the changes. `$fail` simulates the audit store being down.
 */
final class AuditingListener implements ChangeListener
{
    public bool $fail = false;

    public function __construct(private readonly Connection $connection) {}

    public function changed(Changes $changes): void
    {
        foreach ($changes as $change) {
            $this->connection->table('audit')->insert(['entity' => $change->class, 'action' => $change->kind->value, 'after' => json_encode($change->after, \JSON_THROW_ON_ERROR)]);
        }

        if ($this->fail) {
            throw new RuntimeException('audit store is down');
        }
    }
}
