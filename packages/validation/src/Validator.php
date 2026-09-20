<?php

declare(strict_types=1);

namespace Trunk\Validation;

use LogicException;
use Trunk\Error\ValidationException;

/**
 * Turns raw input into a request object, or says exactly which fields are wrong.
 *
 *     $request = $validator->validate(RegisterRequest::class, $input);   // throws ValidationException
 *     $result  = $validator->check(RegisterRequest::class, $input);      // no exception
 *
 * The object is only ever built from valid input, so holding one means the rules passed.
 *
 * @api
 */
final readonly class Validator
{
    /** @internal wired by the container, not part of the API */
    public function __construct(private Plans $plans) {}

    /**
     * @template T of object
     *
     * @param class-string<T>         $class
     * @param array<array-key, mixed> $input
     *
     * @return T
     *
     * @throws ValidationException
     */
    public function validate(string $class, array $input, Source $source = Source::Json): object
    {
        $result = $this->check($class, $input, $source);

        if (!$result->isValid()) {
            throw new ValidationException($result->errors->lists(), rules: $result->errors->rules(), old: $result->old);
        }

        $value = $result->value();

        return $value instanceof $class ? $value : throw new LogicException('The validated value is not a ' . $class . '.');
    }

    /**
     * @param class-string            $class
     * @param array<array-key, mixed> $input
     */
    public function check(string $class, array $input, Source $source = Source::Json): ValidationResult
    {
        return Engine::run($this->plans, $class, $input, $source);
    }
}
