<?php

declare(strict_types=1);

namespace Trunk\Error;

use RuntimeException;

/**
 * The input was well-formed but not acceptable (HTTP 422). Field messages are client-safe by
 * definition, so this is a PublicError. Throw it for a rule only your code can check:
 * `throw ValidationException::field('email', 'Already registered.')`.
 *
 * The response lists messages per field; when the failing rules are known (the validation package
 * supplies them) it adds their stable codes, and `old()` holds the submitted values that are safe to
 * show again.
 *
 * @api
 */
final class ValidationException extends RuntimeException implements PublicError
{
    /**
     * @param array<string, list<string>> $errors field => messages
     * @param array<string, string>       $rules  field => the code of the rule that failed (`email`, `max_length`, ...)
     * @param array<string, scalar>       $old    submitted values that may be shown again (never secrets)
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'The given data was invalid.',
        private readonly array $rules = [],
        private readonly array $old = [],
    ) {
        parent::__construct($message);
    }

    public static function field(string $field, string $message, string $rule = 'invalid'): self
    {
        return new self([$field => [$message]], rules: [$field => $rule]);
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
        return ['fields' => $this->errors] + ($this->rules === [] ? [] : ['rules' => $this->rules]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The first message of each field: what a form shows next to its input.
     *
     * @return array<string, string>
     */
    public function firstMessages(): array
    {
        return array_map(static fn(array $messages): string => $messages[0] ?? '', $this->errors);
    }

    public function first(string $field): ?string
    {
        return ($this->errors[$field] ?? [])[0] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * @return array<string, scalar>
     */
    public function old(): array
    {
        return $this->old;
    }
}
