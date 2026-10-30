<?php

declare(strict_types=1);

namespace Trunk\OpenApi\Generator;

use BackedEnum;
use BcMath\Number;
use DateTimeInterface;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use stdClass;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\OpenApi\Exception\OpenApiException;
use Trunk\Validation\Attribute\ListOf;
use Trunk\Validation\Compiler\PlanBuilder;
use Trunk\Validation\Plan\FieldPlan;
use Trunk\Validation\Plan\Plan;
use Trunk\Validation\Rule;
use Trunk\Validation\Rules\Date;
use Trunk\Validation\Rules\Each;
use Trunk\Validation\Rules\Email;
use Trunk\Validation\Rules\Ip;
use Trunk\Validation\Rules\Items;
use Trunk\Validation\Rules\Length;
use Trunk\Validation\Rules\OneOf;
use Trunk\Validation\Rules\Pattern;
use Trunk\Validation\Rules\Range;
use Trunk\Validation\Rules\Required;
use Trunk\Validation\Rules\RequiredIf;
use Trunk\Validation\Rules\SameAs;
use Trunk\Validation\Rules\Url;
use Trunk\Validation\Rules\Uuid;
use UnitEnum;

/**
 * Describes classes as JSON Schema (OpenAPI 3.1). Input (a request class) is read exactly as the
 * validator reads it, so its rules become schema keywords (`Length` is minLength/maxLength, `Email` a
 * format) and fields with a default are optional. Output (a `#[Returns]` class) is described from its
 * public properties, as `json_encode` writes them: every one is present. Each becomes one component,
 * named once every class is known (see `resolve()`), so the names never depend on route order.
 *
 * @internal
 */
final class SchemaBuilder
{
    /** References point here until `resolve()` gives each component its name. */
    private const string PENDING = '#/components/schemas/@';

    /** @var array<string, array<string, mixed>> "in:" or "out:" and the class => schema */
    private array $components = [];

    /** @var array<class-string, Plan> */
    private array $plans = [];

    public function __construct(private readonly PlanBuilder $planner = new PlanBuilder()) {}

    /**
     * `{"$ref": "#/components/schemas/Name"}` for a request class, describing it the first time.
     *
     * @param class-string $class
     *
     * @return array<string, mixed>
     */
    public function input(string $class): array
    {
        return $this->reference($class, false);
    }

    /**
     * `{"$ref": "#/components/schemas/Name"}` for a response class, describing it the first time.
     *
     * @param class-string $class
     *
     * @return array<string, mixed>
     */
    public function output(string $class): array
    {
        return $this->reference($class, true);
    }

    /**
     * The validator's plan of a request class.
     *
     * @param class-string $class
     *
     * @throws OpenApiException when the class cannot be used as a request class
     */
    public function plan(string $class): Plan
    {
        if (!isset($this->plans[$class])) {
            try {
                $this->plans[$class] = $this->planner->plan($class);
            } catch (CompilationException $e) {
                throw new OpenApiException(implode(' ', $e->errors), previous: $e);
            }
        }

        return $this->plans[$class];
    }

    /**
     * The schema of one field of a request class, with its rules applied.
     *
     * @return array<string, mixed>
     */
    public function field(FieldPlan $field): array
    {
        $schema = match ($field->kind) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'enum' => $field->class !== null && enum_exists($field->class) ? self::enum($field->class) : ['type' => 'string'],
            'object' => $field->class !== null ? $this->input($field->class) : ['type' => 'object'],
            default => ['type' => 'array', 'items' => $field->class !== null ? $this->input($field->class) : new stdClass()],
        };

        foreach ($field->rules as $rule) {
            $schema = self::rule($schema, $rule);
        }

        if ($field->sensitive && ($schema['type'] ?? null) === 'string' && !isset($schema['format'])) {
            $schema['format'] = 'password';
        }

        $schema = $field->nullable ? self::nullable($schema) : $schema;

        if ($field->hasDefault) {
            $default = $field->default instanceof BackedEnum ? $field->default->value : $field->default;

            if ($default === null || \is_scalar($default) || $default === []) {
                $schema['default'] = $default;
            }
        }

        return $schema;
    }

    /**
     * True when the field must be sent: a `Required` rule, or a non-nullable type without a default.
     */
    public static function isRequired(FieldPlan $field): bool
    {
        return (!$field->nullable && !$field->hasDefault) || array_any($field->rules, static fn(Rule $rule): bool => $rule instanceof Required);
    }

    /**
     * The named components, their references resolved.
     *
     * @return array<string, array<string, mixed>>
     */
    public function components(): array
    {
        $names = $this->names();
        $components = [];

        foreach ($this->components as $key => $schema) {
            $components[$names[$key]] = $this->resolve($schema, $names);
        }

        ksort($components);

        return $components;
    }

    /**
     * Replaces the pending references in a part of the document with the final component names.
     *
     * @template K of array-key
     *
     * @param array<K, mixed>            $value
     * @param array<string, string>|null $names
     *
     * @return array<K, mixed>
     */
    public function resolve(array $value, ?array $names = null): array
    {
        $names ??= $this->names();

        foreach ($value as $key => $item) {
            if ($key === '$ref' && \is_string($item) && str_starts_with($item, self::PENDING)) {
                $value[$key] = '#/components/schemas/' . $names[substr($item, \strlen(self::PENDING))];
            } elseif (\is_array($item)) {
                $value[$key] = $this->resolve($item, $names);
            }
        }

        return $value;
    }

    /**
     * @param class-string $class
     *
     * @return array<string, mixed>
     */
    private function reference(string $class, bool $output): array
    {
        $key = ($output ? 'out:' : 'in:') . $class;

        if (!isset($this->components[$key])) {
            $this->components[$key] = [];   // reserved first, so a class that refers to itself ends
            $this->components[$key] = $output ? $this->reflect($class) : $this->describe($this->plan($class));
        }

        return ['$ref' => self::PENDING . $key];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Plan $plan): array
    {
        $properties = [];
        $required = [];

        foreach ($plan->fields as $field) {
            $properties[$field->name] = $this->field($field);

            if (self::isRequired($field)) {
                $required[] = $field->name;
            }
        }

        return self::object($properties, $required);
    }

    /**
     * Every public property, as `json_encode` writes it, so every one is present.
     *
     * @param class-string $class
     *
     * @return array<string, mixed>
     */
    private function reflect(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $listOf = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            foreach ($parameter->getAttributes(ListOf::class) as $attribute) {
                $listOf[$parameter->getName()] = $attribute->newInstance()->class;
            }
        }

        $properties = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $properties[$property->getName()] = $this->type($property->getType(), $listOf[$property->getName()] ?? null);
        }

        return self::object($properties, array_keys($properties));
    }

    /**
     * @return array<string, mixed>
     */
    private function type(?ReflectionType $type, ?string $items): array
    {
        if (!$type instanceof ReflectionNamedType) {
            return [];
        }

        $name = $type->getName();
        $schema = match (true) {
            $name === 'string' => ['type' => 'string'],
            $name === 'int' => ['type' => 'integer'],
            $name === 'float' => ['type' => 'number'],
            $name === 'bool', $name === 'true', $name === 'false' => ['type' => 'boolean'],
            $name === 'array' => ['type' => 'array', 'items' => $items !== null && class_exists($items) ? $this->output($items) : new stdClass()],
            $type->isBuiltin() => [],
            enum_exists($name) && is_subclass_of($name, BackedEnum::class) => self::enum($name),
            is_a($name, DateTimeInterface::class, true) => ['type' => 'string', 'format' => 'date-time'],
            $name === Number::class => ['type' => 'string', 'format' => 'decimal'],
            class_exists($name) => $this->output($name),
            default => [],
        };

        return $type->allowsNull() && $name !== 'mixed' && $name !== 'null' && $schema !== [] ? self::nullable($schema) : $schema;
    }

    /**
     * The short class name, unless two classes share it (then the full name, dotted). A class used both
     * as input and as output names its output `<Name>Response`.
     *
     * @return array<string, string> key => component name
     */
    private function names(): array
    {
        $classes = [];

        foreach (array_keys($this->components) as $key) {
            [, $class] = explode(':', $key, 2);
            $classes[$class][] = $key;
        }

        $byShort = [];

        foreach (array_keys($classes) as $class) {
            $byShort[substr(strrchr('\\' . $class, '\\') ?: $class, 1)][] = $class;
        }

        $names = [];

        foreach ($byShort as $short => $sharing) {
            foreach ($sharing as $class) {
                $base = \count($sharing) > 1 ? str_replace('\\', '.', $class) : $short;

                foreach ($classes[$class] as $key) {
                    $names[$key] = str_starts_with($key, 'out:') && \count($classes[$class]) > 1 ? $base . 'Response' : $base;
                }
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function rule(array $schema, Rule $rule): array
    {
        $type = $schema['type'] ?? null;

        switch (true) {
            case $rule instanceof Required:
                if ($type === 'string') {
                    $schema['minLength'] = max(1, \is_int($schema['minLength'] ?? null) ? $schema['minLength'] : 0);
                } elseif ($type === 'array') {
                    $schema['minItems'] = max(1, \is_int($schema['minItems'] ?? null) ? $schema['minItems'] : 0);
                }

                return $schema;

            case $rule instanceof Length:
                return [...$schema, 'type' => $type ?? 'string', ...array_filter(['minLength' => $rule->min, 'maxLength' => $rule->max], static fn(?int $v): bool => $v !== null)];

            case $rule instanceof Range:
                return [...$schema, 'type' => $type ?? 'number', ...array_filter(['minimum' => $rule->min, 'maximum' => $rule->max], static fn(int|float|null $v): bool => $v !== null)];

            case $rule instanceof Items:
                return [...$schema, ...array_filter(['minItems' => $rule->min, 'maxItems' => $rule->max], static fn(?int $v): bool => $v !== null)];

            case $rule instanceof Email:
                return [...$schema, 'format' => 'email'];

            case $rule instanceof Url:
                return [...$schema, 'format' => 'uri', 'description' => self::note($schema, 'Schemes: ' . implode(', ', $rule->schemes) . '.')];

            case $rule instanceof Uuid:
                return [...$schema, 'format' => 'uuid'];

            case $rule instanceof Ip:
                return $rule->version === null ? $schema : [...$schema, 'format' => 'ipv' . $rule->version];

            case $rule instanceof Date:
                return match ($rule->format) {
                    'Y-m-d' => [...$schema, 'format' => 'date'],
                    'Y-m-d\TH:i:sP', \DATE_ATOM => [...$schema, 'format' => 'date-time'],
                    default => [...$schema, 'description' => self::note($schema, 'A date in the PHP format ' . $rule->format . '.')],
                };

            case $rule instanceof OneOf:
                return [...$schema, 'enum' => $rule->values];

            case $rule instanceof Pattern:
                $pattern = self::ecmaPattern($rule->regex);

                return $pattern === null ? $schema : [...$schema, 'pattern' => $pattern];

            case $rule instanceof Each:
                /** @var array<string, mixed> $items */
                $items = \is_array($schema['items'] ?? null) ? $schema['items'] : [];

                foreach ($rule->rules as $inner) {
                    $items = self::rule($items, $inner);
                }

                return [...$schema, 'items' => $items === [] ? new stdClass() : $items];

            case $rule instanceof RequiredIf:
                return [...$schema, 'description' => self::note($schema, \sprintf('Required when %s is %s.', $rule->field, var_export($rule->equals, true)))];

            case $rule instanceof SameAs:
                return [...$schema, 'description' => self::note($schema, \sprintf('Must equal %s.', $rule->field))];

            default:
                return $schema;   // an application rule: only its code can say what it accepts
        }
    }

    /**
     * A PHP pattern as a JSON Schema (ECMA-262) pattern, when it means the same: one delimiter pair and
     * no flags other than D and u. Anything else is left out rather than described wrongly.
     */
    private static function ecmaPattern(string $regex): ?string
    {
        $delimiter = $regex[0] ?? '';
        $end = strrpos($regex, $delimiter);

        if ($delimiter === '' || ctype_alnum($delimiter) || $delimiter === '\\' || $end === false || $end === 0) {
            return null;
        }

        $flags = substr($regex, $end + 1);

        return trim($flags, 'Du') === '' ? substr($regex, 1, $end - 1) : null;
    }

    /**
     * @param class-string<UnitEnum> $class
     *
     * @return array<string, mixed>
     */
    private static function enum(string $class): array
    {
        $reflection = new ReflectionEnum($class);
        $values = array_map(static fn(ReflectionEnumBackedCase $case): int|string => $case->getBackingValue(), array_filter($reflection->getCases(), static fn(object $case): bool => $case instanceof ReflectionEnumBackedCase));

        return ['type' => (string) $reflection->getBackingType() === 'int' ? 'integer' : 'string', 'enum' => array_values($values)];
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function nullable(array $schema): array
    {
        if (isset($schema['$ref'])) {
            return ['anyOf' => [$schema, ['type' => 'null']]];
        }

        if (\is_string($schema['type'] ?? null)) {
            $schema['type'] = [$schema['type'], 'null'];

            if (isset($schema['enum']) && \is_array($schema['enum'])) {
                $schema['enum'][] = null;
            }
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<string>         $required
     *
     * @return array<string, mixed>
     */
    private static function object(array $properties, array $required): array
    {
        return array_filter(['type' => 'object', 'properties' => $properties === [] ? new stdClass() : $properties, 'required' => $required], static fn(mixed $v): bool => $v !== []);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function note(array $schema, string $text): string
    {
        return \is_string($schema['description'] ?? null) ? $schema['description'] . ' ' . $text : $text;
    }
}
