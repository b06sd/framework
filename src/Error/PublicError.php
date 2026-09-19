<?php

declare(strict_types=1);

namespace Trunk\Error;

/**
 * "This exception's message is safe to show the client." Only exceptions implementing this
 * interface are ever described to clients; every other exception is an internal error and clients
 * see only INTERNAL_ERROR with a request id.
 *
 * @api
 */
interface PublicError extends HasErrorCode
{
    /** The HTTP status this error maps to (4xx or 5xx). */
    public function statusCode(): int;

    /** Client-safe text. Never put internals, SQL, paths or other people's data here. */
    public function publicMessage(): string;

    /**
     * Client-safe structured details (for example the failing fields of a validation error).
     *
     * @return array<string, mixed>
     */
    public function details(): array;
}
