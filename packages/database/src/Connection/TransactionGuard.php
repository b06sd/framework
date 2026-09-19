<?php

declare(strict_types=1);

namespace Trunk\Database\Connection;

use LogicException;
use Trunk\Lifecycle\LifecycleAware;

/**
 * A safety net for workers and other long-running processes: if a unit of work ends with a
 * transaction still open (a begin without a commit), it is rolled back so the next job does not run
 * inside it and no half-finished writes linger. The reset then reports the mistake so it gets logged.
 */
final readonly class TransactionGuard implements LifecycleAware
{
    public function __construct(private ConnectionManager $connections) {}

    /**
     * Rolls back every transaction level still open on any opened connection.
     *
     * @return int how many levels were open
     */
    public function rollBackOpen(): int
    {
        $left = 0;

        foreach ($this->connections->open() as $connection) {
            while ($connection->transactionDepth() > 0) {
                $connection->rollBack();
                ++$left;
            }
        }

        return $left;
    }

    public function reset(): void
    {
        $left = $this->rollBackOpen();

        if ($left > 0) {
            throw new LogicException(\sprintf('A unit of work ended with %d open transaction level(s); they were rolled back. Commit or roll back explicitly, or use Connection::transaction().', $left));
        }
    }
}
