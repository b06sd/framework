<?php

declare(strict_types=1);

namespace Trunk\Validation;

/**
 * The failures of one validation, by field. Field names are paths: `email`, `address.city`, `tags.0`.
 *
 * @api
 */
final readonly class ErrorBag
{
    /**
     * @param array<string, Violation> $violations the first failure of each field
     */
    public function __construct(private array $violations = []) {}

    public function isEmpty(): bool
    {
        return $this->violations === [];
    }

    public function has(string $field): bool
    {
        return isset($this->violations[$field]);
    }

    public function first(string $field): ?string
    {
        return ($this->violations[$field] ?? null)?->message;
    }

    /**
     * @return array<string, string> field => message
     */
    public function messages(): array
    {
        return array_map(static fn(Violation $v): string => $v->message, $this->violations);
    }

    /**
     * @return array<string, string> field => the code of the rule that failed
     */
    public function rules(): array
    {
        return array_map(static fn(Violation $v): string => $v->rule, $this->violations);
    }

    /**
     * @return array<string, list<string>> field => messages, the shape a `ValidationException` carries
     */
    public function lists(): array
    {
        return array_map(static fn(Violation $v): array => [$v->message], $this->violations);
    }

    /**
     * Each field's failure as `{rule, message}`, for code that wants both.
     *
     * @return array<string, array{rule: string, message: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn(Violation $v): array => ['rule' => $v->rule, 'message' => $v->message], $this->violations);
    }
}
