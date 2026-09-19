<?php

declare(strict_types=1);

namespace Trunk\Error;

use RuntimeException;

/**
 * The input was well-formed but not acceptable (HTTP 422). Field messages are client-safe by
 * definition, so this is a PublicError.
 *
 * @api
 */
final class ValidationException extends RuntimeException implements PublicError
{
    /**
     * @param array<string, list<string>> $errors field => messages
     */
    public function __construct(private readonly array $errors, string $message = 'The given data was invalid.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return ErrorCode::ValidationFailed->value;
    }

    public function statusCode(): int
    {
        return 422;
    }

    public function publicMessage(): string
    {
        return $this->getMessage();
    }

    public function details(): array
    {
        return ['fields' => $this->errors];
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
