<?php

declare(strict_types=1);

namespace Trunk\Logging;

use BackedEnum;
use DateTimeInterface;
use Throwable;
use UnitEnum;

/**
 * Turns whatever callers put in a log context into bounded, JSON-safe data: strings are scrubbed
 * and sanitised, secrets redacted by key, exceptions reduced to class/message/location/trace
 * (never arguments), objects to their class name, resources to their type. Depth and size are
 * capped so a log call can never be used to dump a large structure.
 */
final readonly class ContextNormalizer
{
    private const int MAX_DEPTH = 6;

    private const int MAX_ITEMS = 100;

    private const int MAX_FRAMES = 20;

    private const int MAX_PREVIOUS = 5;

    public function __construct(private Redactor $redactor = new Redactor()) {}

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(array $context): array
    {
        $result = [];

        foreach (\array_slice($context, 0, self::MAX_ITEMS, true) as $key => $value) {
            $name = Sanitizer::text($this->redactor->scrub((string) $key), 128);
            $result[$name] = $this->redactor->sensitive($name) ? Redactor::MASK : $this->value($value, 1);
        }

        return $result;
    }

    public function sensitive(string $key): bool
    {
        return $this->redactor->sensitive($key);
    }

    public function text(string $text): string
    {
        return Sanitizer::text($this->redactor->scrub($text));
    }

    private function value(mixed $value, int $depth): mixed
    {
        return match (true) {
            $value === null, \is_bool($value), \is_int($value) => $value,
            \is_float($value) => is_finite($value) ? $value : (is_nan($value) ? 'NaN' : ($value > 0 ? 'Infinity' : '-Infinity')),
            \is_string($value) => $this->text($value),
            $value instanceof Throwable => $this->exception($value),
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            \is_array($value) => $this->items($value, $depth),
            \is_object($value) => '[object ' . $value::class . ']',
            \is_resource($value) => '[resource ' . get_resource_type($value) . ']',
            default => '[' . get_debug_type($value) . ']',
        };
    }

    /**
     * @param array<array-key, mixed> $items
     *
     * @return array<array-key, mixed>|string
     */
    private function items(array $items, int $depth): array|string
    {
        if ($depth > self::MAX_DEPTH) {
            return '[nested too deeply]';
        }

        $result = [];

        foreach (\array_slice($items, 0, self::MAX_ITEMS, true) as $key => $value) {
            $name = \is_int($key) ? $key : Sanitizer::text($this->redactor->scrub($key), 128);
            $result[$name] = \is_string($name) && $this->redactor->sensitive($name) ? Redactor::MASK : $this->value($value, $depth + 1);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function exception(Throwable $error): array
    {
        $data = [
            'class' => $error::class,
            'message' => $this->text($error->getMessage()),
            'code' => $error->getCode(),
            'file' => Sanitizer::text($error->getFile(), 512),
            'line' => $error->getLine(),
            'trace' => $this->trace($error),
        ];
        $previous = [];

        for ($p = $error->getPrevious(); $p !== null && \count($previous) < self::MAX_PREVIOUS; $p = $p->getPrevious()) {
            $previous[] = ['class' => $p::class, 'message' => $this->text($p->getMessage())];
        }

        if ($previous !== []) {
            $data['previous'] = $previous;
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    private function trace(Throwable $error): array
    {
        $frames = [];

        foreach (\array_slice($error->getTrace(), 0, self::MAX_FRAMES) as $frame) {
            $frames[] = Sanitizer::text(($frame['file'] ?? '[internal]') . ':' . ($frame['line'] ?? 0) . ' ' . ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'] . '()', 512);
        }

        return $frames;
    }
}
