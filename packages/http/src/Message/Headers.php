<?php

declare(strict_types=1);

namespace Trunk\Http\Message;

use InvalidArgumentException;

/**
 * Immutable, case-insensitive header collection. Names must be RFC 7230 tokens and values may not
 * contain CR, LF, NUL or other control characters, so header injection is impossible by construction.
 */
final readonly class Headers
{
    /**
     * @param array<string, array{string, list<string>}> $entries lowercase name => [original name, values]
     */
    private function __construct(private array $entries = []) {}

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    public static function fromArray(array $headers): self
    {
        $result = new self();

        foreach ($headers as $name => $value) {
            $result = $result->withAdded((string) $name, $value);
        }

        return $result;
    }

    public static function assertName(string $name): void
    {
        if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1) {
            throw new InvalidArgumentException('Invalid header name.');
        }
    }

    public static function isValidValue(string $value): bool
    {
        return preg_match('/^[\x09\x20-\x7E\x80-\xFF]*$/D', $value) === 1;
    }

    public function has(string $name): bool
    {
        return isset($this->entries[strtolower($name)]);
    }

    /**
     * @return list<string>
     */
    public function get(string $name): array
    {
        return $this->entries[strtolower($name)][1] ?? [];
    }

    /**
     * @return array<string, list<string>> original-case name => values
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->entries as [$name, $values]) {
            $all[$name] = $values;
        }

        return $all;
    }

    public function with(string $name, mixed $value): self
    {
        $values = self::normalize($name, $value);
        $entries = $this->entries;
        $entries[strtolower($name)] = [$name, $values];

        return new self($entries);
    }

    public function withAdded(string $name, mixed $value): self
    {
        $values = self::normalize($name, $value);
        $key = strtolower($name);
        $entries = $this->entries;
        $entries[$key] = [$entries[$key][0] ?? $name, [...($entries[$key][1] ?? []), ...$values]];

        return new self($entries);
    }

    /**
     * Adds each header that is not present yet, in one step. The pairs are trusted (already validated by
     * the caller, keyed by lowercase name), which is what makes this cheaper than repeated `with()`.
     * Returns this same instance when there was nothing to add.
     *
     * @param array<string, array{string, string}> $prepared lowercase name => [name, value]
     */
    public function withMissing(array $prepared): self
    {
        $entries = $this->entries;
        $added = false;

        foreach ($prepared as $key => [$name, $value]) {
            if (!isset($entries[$key])) {
                $entries[$key] = [$name, [$value]];
                $added = true;
            }
        }

        return $added ? new self($entries) : $this;
    }

    public function without(string $name): self
    {
        $entries = $this->entries;
        unset($entries[strtolower($name)]);

        return new self($entries);
    }

    /**
     * Places a header first, replacing any existing one (used for Host).
     */
    public function withFirst(string $name, string $value): self
    {
        $values = self::normalize($name, $value);
        $entries = [strtolower($name) => [$name, $values]];

        foreach ($this->entries as $key => $entry) {
            if ($key !== strtolower($name)) {
                $entries[$key] = $entry;
            }
        }

        return new self($entries);
    }

    /**
     * @return list<string>
     */
    private static function normalize(string $name, mixed $value): array
    {
        self::assertName($name);
        $values = \is_array($value) ? array_values($value) : [$value];

        if ($values === []) {
            throw new InvalidArgumentException('A header must have at least one value.');
        }

        $normalized = [];

        foreach ($values as $item) {
            if (!\is_string($item)) {
                throw new InvalidArgumentException('Header values must be strings.');
            }

            $item = trim($item, " \t");

            if (!self::isValidValue($item)) {
                throw new InvalidArgumentException('Header value contains forbidden characters.');
            }

            $normalized[] = $item;
        }

        return $normalized;
    }
}
