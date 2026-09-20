<?php

declare(strict_types=1);

namespace Trunk\Validation;

use BackedEnum;
use Trunk\Validation\Plan\FieldPlan;
use Trunk\Validation\Plan\Plan;

/**
 * Runs a plan against input. One code path for development and production. Bounded on purpose: nesting
 * stops at 8 levels, a list at 1000 entries, and reporting at 100 failures, so hostile input costs
 * a fixed amount of work.
 */
final class Engine
{
    private const int MAX_DEPTH = 8;
    private const int MAX_LIST = 1000;
    private const int MAX_ERRORS = 100;
    private const int MAX_OLD_BYTES = 1000;

    /** @var array<string, Violation> */
    private array $violations = [];

    /** @var array<string, scalar> */
    private array $old = [];

    private function __construct(private readonly Plans $plans, private readonly Source $source) {}

    /**
     * @param class-string            $class
     * @param array<array-key, mixed> $input
     */
    public static function run(Plans $plans, string $class, array $input, Source $source): ValidationResult
    {
        $engine = new self($plans, $source);
        $value = $engine->object($plans->plan($class), $input, '', 0);
        $errors = new ErrorBag($engine->violations);

        return new ValidationResult($errors->isEmpty() ? $value : null, $errors, $engine->old);
    }

    /**
     * @param array<array-key, mixed> $input
     */
    private function object(Plan $plan, array $input, string $path, int $depth): ?object
    {
        if ($depth > self::MAX_DEPTH) {
            $this->fail($path, 'nesting', 'Is nested too deeply.');

            return null;
        }

        $arguments = [];
        $valid = true;

        foreach ($plan->fields as $field) {
            $name = $path === '' ? $field->name : $path . '.' . $field->name;
            $context = new Context($name, $input);
            $raw = $input[$field->name] ?? null;

            if ($raw === null || ($raw === '' && $this->source->isText() && $field->kind !== 'string')) {
                $valid = $this->absent($field, $context, $arguments) && $valid;

                continue;
            }

            if (!$field->sensitive && \is_scalar($raw) && !(\is_float($raw) && !is_finite($raw))) {
                $this->old[$name] = \is_string($raw) ? substr($raw, 0, self::MAX_OLD_BYTES) : $raw;
            }

            [$ok, $value] = $this->convert($field, $raw, $name, $depth);

            if ($ok) {
                $ok = $this->check($field, $value, $context);
            }

            $arguments[$field->name] = $value;
            $valid = $ok && $valid;
        }

        return $valid ? new ($plan->class)(...$arguments) : null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function absent(FieldPlan $field, Context $context, array &$arguments): bool
    {
        foreach ($field->rules as $rule) {
            $violation = $rule instanceof PresenceRule ? $rule->whenMissing($context) : null;

            if ($violation !== null) {
                $this->add($context->field, $violation);

                return false;
            }
        }

        if (!$field->hasDefault && !$field->nullable) {
            $this->fail($context->field, 'required', 'This field is required.');

            return false;
        }

        $arguments[$field->name] = $field->hasDefault ? $field->default : null;

        return true;
    }

    private function check(FieldPlan $field, mixed $value, Context $context): bool
    {
        foreach ($field->rules as $rule) {
            $violation = $rule->check($value, $context);

            if ($violation !== null) {
                $this->add($context->field, $violation);

                return false;
            }
        }

        return true;
    }

    /**
     * Turns the raw value into the declared type, or reports why it cannot be.
     *
     * @return array{bool, mixed}
     */
    private function convert(FieldPlan $field, mixed $raw, string $name, int $depth): array
    {
        $text = $this->source->isText();

        return match ($field->kind) {
            'string' => \is_string($raw) ? $this->text($raw, $name) : $this->type($name, 'Must be text.'),
            'int' => match (true) {
                \is_int($raw) => [true, $raw],
                $text && \is_string($raw) && preg_match('/^-?\d{1,18}$/D', $raw) === 1 => [true, (int) $raw],
                default => $this->type($name, 'Must be a whole number.'),
            },
            'float' => match (true) {
                \is_int($raw) => [true, (float) $raw],
                \is_float($raw) && is_finite($raw) => [true, $raw],
                $text && \is_string($raw) && preg_match('/^-?\d{1,15}(?:\.\d{1,15})?$/D', $raw) === 1 => [true, (float) $raw],
                default => $this->type($name, 'Must be a number.'),
            },
            'bool' => match (true) {
                \is_bool($raw) => [true, $raw],
                $text && \in_array($raw, ['1', 'true', 'on'], true) => [true, true],
                $text && \in_array($raw, ['0', 'false', 'off'], true) => [true, false],
                default => $this->type($name, 'Must be true or false.'),
            },
            'enum' => $this->enum($field, $raw, $name, $text),
            'array' => $this->list($field, $raw, $name, $depth),
            default => $this->nested($field, $raw, $name, $depth),
        };
    }

    /**
     * @return array{bool, mixed}
     */
    private function text(string $value, string $name): array
    {
        return mb_check_encoding($value, 'UTF-8') && !str_contains($value, "\0") ? [true, $value] : $this->type($name, 'Must be valid text.');
    }

    /**
     * @return array{bool, mixed}
     */
    private function enum(FieldPlan $field, mixed $raw, string $name, bool $text): array
    {
        $enum = $field->class;

        if ($enum === null || !is_subclass_of($enum, BackedEnum::class)) {
            return $this->type($name, 'Must be one of the allowed values.', 'one_of');
        }

        $backing = ($enum::cases()[0] ?? null)?->value;
        $key = match (true) {
            \is_int($backing) => match (true) {
                \is_int($raw) => $raw,
                $text && \is_string($raw) && preg_match('/^-?\d{1,18}$/D', $raw) === 1 => (int) $raw,
                default => null,
            },
            \is_string($backing) => \is_string($raw) && mb_check_encoding($raw, 'UTF-8') && !str_contains($raw, "\0") ? $raw : null,
            default => null,
        };
        $case = $key === null ? null : $enum::tryFrom($key);

        return $case !== null ? [true, $case] : $this->type($name, 'Must be one of the allowed values.', 'one_of');
    }

    /**
     * @return array{bool, mixed}
     */
    private function list(FieldPlan $field, mixed $raw, string $name, int $depth): array
    {
        if (!\is_array($raw)) {
            return $this->type($name, 'Must be a list.');
        }

        if (\count($raw) > self::MAX_LIST) {
            return $this->type($name, \sprintf('Must have at most %d items.', self::MAX_LIST), 'max_items');
        }

        if ($field->class === null) {
            return [true, $raw];
        }

        $items = [];
        $valid = true;

        foreach (array_values($raw) as $index => $item) {
            if (!\is_array($item)) {
                return $this->type($name . '.' . $index, 'Must be an object.');
            }

            $object = $this->object($this->plans->plan($field->class), $item, $name . '.' . $index, $depth + 1);
            $valid = $valid && $object !== null;
            $items[] = $object;
        }

        return [$valid, $items];
    }

    /**
     * @return array{bool, mixed}
     */
    private function nested(FieldPlan $field, mixed $raw, string $name, int $depth): array
    {
        if (!\is_array($raw) || $field->class === null) {
            return $this->type($name, 'Must be an object.');
        }

        $object = $this->object($this->plans->plan($field->class), $raw, $name, $depth + 1);

        return [$object !== null, $object];
    }

    /**
     * @return array{false, null}
     */
    private function type(string $name, string $message, string $rule = 'type'): array
    {
        $this->fail($name, $rule, $message);

        return [false, null];
    }

    private function fail(string $name, string $rule, string $message): void
    {
        $this->add($name, new Violation($rule, $message));
    }

    private function add(string $name, Violation $violation): void
    {
        if (\count($this->violations) < self::MAX_ERRORS) {
            $this->violations[$name] ??= $violation;
        }
    }
}
