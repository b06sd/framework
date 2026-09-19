<?php

declare(strict_types=1);

namespace Trunk\Database\Exception;

use RuntimeException;
use Throwable;
use Trunk\Error\ErrorCode;
use Trunk\Error\HasErrorCode;

/**
 * A statement failed. The message holds the SQLSTATE and a generic category only: database
 * servers put row values in their messages (MySQL "Duplicate entry 'a@b.c'"), so the driver's own
 * message is deliberately not exposed. The SQL text (placeholders, never values) is available for
 * debugging.
 *
 * @api
 */
final class QueryException extends RuntimeException implements HasErrorCode
{
    public function __construct(
        public readonly string $sql,
        public readonly string $sqlState,
        string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('Query failed (SQLSTATE %s): %s', $sqlState, $reason), 0, $previous);
    }
    public function errorCode(): string
    {
        return ErrorCode::DatabaseError->value;
    }

    public static function fromSqlState(string $sql, string $sqlState, ?Throwable $previous = null): self
    {
        $reason = match (substr($sqlState, 0, 2)) {
            '23' => 'integrity constraint violation',
            '42' => 'syntax error or unknown table/column',
            '22' => 'invalid data',
            '40' => 'transaction conflict (deadlock or serialization failure)',
            '08' => 'connection problem',
            '53', '54' => 'resource limit reached',
            default => 'database error',
        };

        return new self($sql, $sqlState, $reason, $previous);
    }
}
