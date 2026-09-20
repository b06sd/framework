<?php

declare(strict_types=1);

namespace Trunk\Validation;

use LogicException;

/**
 * The outcome of `Validator::check()`: the request object when the input is valid, the errors and the
 * safe-to-redisplay input when it is not.
 *
 * @api
 */
final readonly class ValidationResult
{
    /**
     * @param array<string, scalar> $old submitted values that may be shown again (never fields marked `Sensitive`)
     *
     * @internal built by the validator
     */
    public function __construct(private ?object $value, public ErrorBag $errors, public array $old = []) {}

    public function isValid(): bool
    {
        return $this->value !== null;
    }

    public function value(): object
    {
        return $this->value ?? throw new LogicException('The input is not valid: check isValid() before value().');
    }
}
