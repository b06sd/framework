<?php

declare(strict_types=1);

namespace Trunk\Queue\Job;

use BackedEnum;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;
use Trunk\Queue\Exception\JobMappingException;
use Trunk\Support\ClassName;

/**
 * Validates job classes and turns them into JobMetadata. The only place that reflects on jobs: it
 * runs at build time (or once per process in development), never in production. Every problem
 * is collected and reported together, each with the fix.
 */
final class JobMetadataFactory
{
    private const array SCALARS = ['int' => PayloadType::Int, 'float' => PayloadType::Float, 'string' => PayloadType::String, 'bool' => PayloadType::Bool, 'array' => PayloadType::Array];

    /**
     * @param list<string> $classes
     *
     * @return array<string, JobMetadata> name => metadata
     *
     * @throws JobMappingException
     */
    public function fromClasses(array $classes): array
    {
        $metadata = [];
        $errors = [];

        foreach ($classes as $class) {
            if (!ClassName::isValid($class) || !class_exists($class)) {
                $errors[] = \sprintf('queue.jobs lists "%s", which is not a class. Check the name and run `composer dump-autoload`.', self::printable($class));

                continue;
            }

            if (!is_subclass_of($class, Job::class)) {
                $errors[] = \sprintf('%s must implement %s.', $class, Job::class);

                continue;
            }

            $problems = [];
            $built = $this->build($class, $problems);

            if ($problems !== []) {
                array_push($errors, ...$problems);
            } elseif ($built !== null) {
                $metadata[$built->name] = $built;
            }
        }

        if ($errors !== []) {
            throw new JobMappingException($errors);
        }

        return $metadata;
    }

    /**
     * @param class-string $class
     * @param list<string> $errors
     */
    private function build(string $class, array &$errors): ?JobMetadata
    {
        $reflection = new ReflectionClass($class);
        $where = static fn(string $m): string => $class . ': ' . $m;
        $before = \count($errors);

        if (!$reflection->isInstantiable()) {
            $errors[] = $where('a job must be a concrete class.');

            return null;
        }

        $fields = [];
        $constructor = $reflection->getConstructor();

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if ($parameter->isVariadic() || $parameter->isPassedByReference()) {
                $errors[] = $where(\sprintf('constructor parameter "%s" cannot be variadic or by reference.', $name));

                continue;
            }

            if (!$reflection->hasProperty($name) || !$reflection->getProperty($name)->isPublic()) {
                $errors[] = $where(\sprintf('constructor parameter "%s" needs a public property of the same name (e.g. `public int $%s`) so the payload can be written.', $name, $name));

                continue;
            }

            if (!$type instanceof ReflectionNamedType) {
                $errors[] = $where(\sprintf('constructor parameter "%s" needs a single declared type (int, float, string, bool, array, a backed enum or DateTimeImmutable).', $name));

                continue;
            }

            $field = $this->field($name, $type, $parameter->isOptional());

            if ($field === null) {
                $errors[] = $where(\sprintf('constructor parameter "%s" has type %s, which cannot be queued. Use int, float, string, bool, array, a backed enum or DateTimeImmutable, and pass an id instead of an object or entity.', $name, $type->getName()));

                continue;
            }

            $fields[] = $field;
        }

        $dependencies = $this->dependencies($reflection, $where, $errors);
        $options = $this->options($reflection, $where, $errors);

        if (\count($errors) > $before || $options === null) {
            return null;
        }

        return new JobMetadata($class, ltrim($class, '\\'), $options, $fields, $dependencies);
    }

    private function field(string $name, ReflectionNamedType $type, bool $optional): ?Field
    {
        $typeName = $type->getName();
        $nullable = $type->allowsNull();

        if ($type->isBuiltin()) {
            $payload = self::SCALARS[$typeName] ?? null;

            return $payload === null ? null : new Field($name, $payload, $nullable, $optional);
        }

        if ($typeName === DateTimeImmutable::class) {
            return new Field($name, PayloadType::DateTime, $nullable, $optional);
        }

        if (enum_exists($typeName) && is_subclass_of($typeName, BackedEnum::class)) {
            return new Field($name, PayloadType::Enum, $nullable, $optional, $typeName);
        }

        return null;
    }

    /**
     * @param ReflectionClass<object>              $reflection
     * @param callable(string): string             $where
     * @param list<string>                         $errors
     *
     * @return list<class-string>
     */
    private function dependencies(ReflectionClass $reflection, callable $where, array &$errors): array
    {
        if (!$reflection->hasMethod('handle')) {
            $errors[] = $where('a job needs a public `handle()` method.');

            return [];
        }

        $handle = $reflection->getMethod('handle');

        if (!$handle->isPublic() || $handle->isStatic()) {
            $errors[] = $where('handle() must be a public, non-static method.');

            return [];
        }

        $dependencies = [];

        foreach ($handle->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin() || $type->allowsNull() || $parameter->isVariadic()) {
                $errors[] = $where(\sprintf('handle() parameter "%s" must be a class or interface type (a service to inject), not nullable, variadic or built-in. Put job data in the constructor.', $parameter->getName()));

                continue;
            }

            if ($type->getName() === ContainerInterface::class) {
                $errors[] = $where(\sprintf('handle() parameter "%s" asks for the container. Inject the services the job needs instead (a container parameter is a service locator).', $parameter->getName()));

                continue;
            }

            /** @var class-string $service */
            $service = $type->getName();
            $dependencies[] = $service;
        }

        return $dependencies;
    }

    /**
     * @param ReflectionClass<object>  $reflection
     * @param callable(string): string $where
     * @param list<string>             $errors
     */
    private function options(ReflectionClass $reflection, callable $where, array &$errors): ?JobOptions
    {
        if (!$reflection->hasMethod('options')) {
            return new JobOptions();
        }

        $method = $reflection->getMethod('options');

        if (!$method->isPublic() || !$method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
            $errors[] = $where('options() must be `public static function options(): JobOptions` with no parameters.');

            return null;
        }

        try {
            $options = $method->invoke(null);
        } catch (Throwable $e) {
            $errors[] = $where('options() is invalid: ' . $e->getMessage());

            return null;
        }

        if (!$options instanceof JobOptions) {
            $errors[] = $where('options() must return a ' . JobOptions::class . '.');

            return null;
        }

        return $options;
    }

    private static function printable(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.\\\\\-, ]/', '?', substr($value, 0, 60)) ?? '?';
    }
}
