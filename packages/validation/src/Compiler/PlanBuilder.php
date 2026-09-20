<?php

declare(strict_types=1);

namespace Trunk\Validation\Compiler;

use BackedEnum;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Throwable;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Validation\Attribute\From;
use Trunk\Validation\Attribute\ListOf;
use Trunk\Validation\Attribute\Sensitive;
use Trunk\Validation\Plan\FieldPlan;
use Trunk\Validation\Plan\Plan;
use Trunk\Validation\Rule;

/**
 * Reads a request class once (constructor parameters, types, defaults, attributes) and says what is
 * wrong with it in words a developer can act on. Used by `trunk build` and by development mode.
 */
final class PlanBuilder
{
    /**
     * @throws CompilationException
     */
    public function plan(string $class): Plan
    {
        $errors = [];
        $plan = $this->read($class, $errors);

        return $plan !== null && $errors === [] ? $plan : throw new CompilationException($errors === [] ? [$class . ' cannot be used as a request class.'] : $errors);
    }

    /**
     * The plans of the given classes and of every request class they refer to.
     *
     * @param list<string> $classes
     *
     * @return array<class-string, Plan>
     *
     * @throws CompilationException
     */
    public function all(array $classes): array
    {
        $plans = [];
        $errors = [];
        $queue = $classes;

        while ($queue !== []) {
            $class = array_shift($queue);

            if (isset($plans[$class])) {
                continue;
            }

            $plan = $this->read($class, $errors);

            if ($plan === null) {
                continue;
            }

            $plans[$plan->class] = $plan;

            foreach ($plan->fields as $field) {
                if (($field->kind === 'object' || $field->kind === 'array') && $field->class !== null) {
                    $queue[] = $field->class;
                }
            }
        }

        return $errors === [] ? $plans : throw new CompilationException($errors);
    }

    /**
     * @param list<string> $errors
     */
    private function read(string $class, array &$errors): ?Plan
    {
        if (!class_exists($class)) {
            $errors[] = \sprintf('%s does not exist. Check the class name and its namespace.', $class);

            return null;
        }

        $reflection = new ReflectionClass($class);

        $constructor = $reflection->getConstructor();

        if (!$reflection->isInstantiable() || $constructor === null || !$constructor->isPublic()) {
            $errors[] = \sprintf('%s needs a public constructor: its parameters are the fields of the request.', $class);

            return null;
        }

        $source = ($reflection->getAttributes(From::class)[0] ?? null)?->newInstance()->source;
        $fields = [];

        foreach ($constructor->getParameters() as $parameter) {
            $field = $this->field($class, $parameter, $errors);

            if ($field !== null) {
                $fields[] = $field;
            }
        }

        return new Plan($class, $source, $fields);
    }

    /**
     * @param class-string $class
     * @param list<string> $errors
     */
    private function field(string $class, ReflectionParameter $parameter, array &$errors): ?FieldPlan
    {
        $where = \sprintf('%s::$%s', $class, $parameter->getName());
        $type = $this->type($parameter, $where, $errors);

        if ($type === null) {
            return null;
        }

        [$kind, $target, $nullable] = $type;
        $rules = [];
        $sensitive = false;

        foreach ($parameter->getAttributes() as $attribute) {
            $name = $attribute->getName();

            if (!is_a($name, Rule::class, true) && $name !== Sensitive::class && $name !== ListOf::class) {
                continue;
            }

            try {
                $instance = $attribute->newInstance();
            } catch (Throwable $e) {
                $errors[] = \sprintf('%s: #[%s] is not valid: %s', $where, basename(str_replace('\\', '/', $name)), $e->getMessage());

                continue;
            }

            match (true) {
                $instance instanceof Rule => $rules[] = $instance,
                $instance instanceof Sensitive => $sensitive = true,
                $instance instanceof ListOf => $target = $kind === 'array' ? $instance->class : $this->misplaced($where, 'ListOf', 'an array parameter', $errors),
                default => null,
            };
        }

        $hasDefault = $parameter->isDefaultValueAvailable();

        return new FieldPlan($parameter->getName(), $kind, $target, $nullable, $hasDefault, $hasDefault ? $parameter->getDefaultValue() : null, $rules, $sensitive);
    }

    /**
     * @param list<string> $errors
     *
     * @return array{'string'|'int'|'float'|'bool'|'array'|'enum'|'object', class-string|null, bool}|null
     */
    private function type(ReflectionParameter $parameter, string $where, array &$errors): ?array
    {
        $type = $parameter->getType();
        $nullable = $type?->allowsNull() ?? false;

        if ($type instanceof ReflectionUnionType) {
            $named = array_values(array_filter($type->getTypes(), static fn($t): bool => !($t instanceof ReflectionNamedType && $t->getName() === 'null')));
            $type = \count($named) === 1 ? $named[0] : null;
        }

        if (!$type instanceof ReflectionNamedType) {
            $errors[] = \sprintf('%s needs one declared type (string, int, float, bool, array, a backed enum or a request class), optionally nullable.', $where);

            return null;
        }

        $name = $type->getName();

        if ($type->isBuiltin()) {
            if (!\in_array($name, ['string', 'int', 'float', 'bool', 'array'], true)) {
                $errors[] = \sprintf('%s is declared %s, which cannot be validated. Use string, int, float, bool, array, a backed enum or a request class.', $where, $name);

                return null;
            }

            /** @var 'string'|'int'|'float'|'bool'|'array' $name */
            return [$name, null, $nullable];
        }

        if (!class_exists($name) && !enum_exists($name)) {
            $errors[] = \sprintf('%s: the type %s does not exist.', $where, $name);

            return null;
        }

        if (enum_exists($name)) {
            if (!new ReflectionEnum($name)->isBacked() || !is_subclass_of($name, BackedEnum::class)) {
                $errors[] = \sprintf('%s: %s must be a backed enum.', $where, $name);

                return null;
            }

            return ['enum', $name, $nullable];
        }

        return ['object', $name, $nullable];
    }

    /**
     * @param list<string> $errors
     */
    private function misplaced(string $where, string $attribute, string $needs, array &$errors): null
    {
        $errors[] = \sprintf('%s: #[%s] belongs on %s.', $where, $attribute, $needs);

        return null;
    }
}
