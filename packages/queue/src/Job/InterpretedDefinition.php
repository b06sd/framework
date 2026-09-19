<?php

declare(strict_types=1);

namespace Trunk\Queue\Job;

use BackedEnum;
use DateTimeInterface;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use Trunk\Queue\Exception\InvalidPayload;

/**
 * The development codec: the same conversions as the generated code, driven by metadata. Uses
 * named-argument construction and get_object_vars(); no eval.
 */
final readonly class InterpretedDefinition implements JobDefinition
{
    public function __construct(private JobMetadata $metadata) {}

    public function metadata(): JobMetadata
    {
        return $this->metadata;
    }

    public function encode(Job $job): array
    {
        $values = get_object_vars($job);
        $data = [];

        foreach ($this->metadata->fields as $field) {
            $value = $values[$field->name] ?? null;
            $data[$field->name] = match (true) {
                $value === null => null,
                $field->type === PayloadType::Enum && $value instanceof BackedEnum => $value->value,
                $field->type === PayloadType::DateTime && $value instanceof DateTimeInterface => PayloadConvert::dateTimeToPayload($value),
                $field->type === PayloadType::Array && \is_array($value) => PayloadConvert::arrayToPayload($value, $this->metadata->name, $field->name),
                default => $value,
            };
        }

        return $data;
    }

    public function decode(array $data): Job
    {
        $name = $this->metadata->name;
        $known = array_column(array_map(static fn(Field $f): array => [$f->name], $this->metadata->fields), 0);

        foreach (array_keys($data) as $key) {
            if (!\in_array($key, $known, true)) {
                throw PayloadConvert::unexpected($name);
            }
        }

        $arguments = [];

        foreach ($this->metadata->fields as $field) {
            if (!\array_key_exists($field->name, $data)) {
                if ($field->optional) {
                    continue;
                }

                throw PayloadConvert::missing($name, $field->name);
            }

            $value = $data[$field->name];

            if ($value === null) {
                $arguments[$field->name] = $field->nullable ? null : throw PayloadConvert::nullFailure($name, $field->name, $field->type->value);

                continue;
            }

            $arguments[$field->name] = match ($field->type) {
                PayloadType::Int => PayloadConvert::int($value, $name, $field->name),
                PayloadType::Float => PayloadConvert::float($value, $name, $field->name),
                PayloadType::String => PayloadConvert::string($value, $name, $field->name),
                PayloadType::Bool => PayloadConvert::bool($value, $name, $field->name),
                PayloadType::Array => PayloadConvert::array($value, $name, $field->name),
                PayloadType::DateTime => PayloadConvert::dateTime($value, $name, $field->name),
                PayloadType::Enum => PayloadConvert::enum($value, $field->enum ?? throw PayloadConvert::nullFailure($name, $field->name, 'enum'), $name, $field->name),
            };
        }

        $job = new ($this->metadata->class)(...$arguments);

        return $job instanceof Job ? $job : throw new InvalidPayload(\sprintf('%s is not a job.', $name));
    }

    public function invoke(ContainerInterface $scope, Job $job): void
    {
        new ReflectionMethod($job, 'handle')->invokeArgs($job, array_map(static fn(string $id): mixed => $scope->get($id), $this->metadata->dependencies));
    }
}
