<?php

declare(strict_types=1);

namespace Trunk\Queue\Exception;

use RuntimeException;

/**
 * A job returned with a database transaction still open. The transaction was rolled back, so the
 * job's writes are gone: it counts as a failed attempt (retried, then dead-lettered), never as a success.
 *
 * @api
 */
final class TransactionLeftOpen extends RuntimeException
{
    public static function levels(int $levels): self
    {
        return new self(\sprintf('The job ended with %d open transaction level(s); they were rolled back. Use Connection::transaction() or commit explicitly.', $levels));
    }
}
