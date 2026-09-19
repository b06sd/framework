<?php

declare(strict_types=1);

namespace Trunk\Tusk\Runtime;

use Countable;
use InvalidArgumentException;
use Stringable;

/**
 * The whitelist of things a template can call. ARITY is used by the parser to validate filter
 * names and argument counts at build time.
 */
final class Filters
{
    /** @var array<string, array{int, int}> filter => [min args, max args] */
    public const array ARITY = [
        'upper' => [0, 0],
        'lower' => [0, 0],
        'trim' => [0, 0],
        'length' => [0, 0],
        'default' => [1, 1],
        'join' => [0, 1],
        'json' => [0, 0],
        'js' => [0, 0],
        'url' => [0, 0],
    ];

    /**
     * @param list<mixed> $arguments
     */
    public function apply(string $name, mixed $value, array $arguments): mixed
    {
        return match ($name) {
            'upper' => mb_strtoupper($this->text($value)),
            'lower' => mb_strtolower($this->text($value)),
            'trim' => trim($this->text($value)),
            'length' => $this->length($value),
            'default' => $value === null || $value === '' ? ($arguments[0] ?? '') : $value,
            'join' => $this->join($value, $arguments[0] ?? ', '),
            'json' => json_encode($value, \JSON_THROW_ON_ERROR),
            'js' => new SafeHtml(json_encode($value, \JSON_THROW_ON_ERROR | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT)),
            'url' => new SafeHtml(rawurlencode($this->text($value))),
            default => throw new InvalidArgumentException(\sprintf('Unknown filter "%s".', $name)),
        };
    }

    public function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            \is_bool($value) => $value ? '1' : '',
            $value instanceof Stringable => (string) $value,
            default => throw new InvalidArgumentException(\sprintf('A value of type %s cannot be printed.', get_debug_type($value))),
        };
    }

    private function length(mixed $value): int
    {
        return match (true) {
            \is_string($value) => mb_strlen($value),
            \is_array($value), $value instanceof Countable => \count($value),
            default => throw new InvalidArgumentException(\sprintf('"length" needs a string or a collection, %s given.', get_debug_type($value))),
        };
    }

    private function join(mixed $value, mixed $separator): string
    {
        if (!\is_array($value)) {
            throw new InvalidArgumentException(\sprintf('"join" needs an array, %s given.', get_debug_type($value)));
        }

        return implode($this->text($separator), array_map($this->text(...), $value));
    }
}
