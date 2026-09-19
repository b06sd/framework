<?php

declare(strict_types=1);

namespace Trunk\Foundation;

use Trunk\Foundation\Exception\ConfigurationException;

/**
 * Immutable configuration repository with dot-notation lookup.
 *
 * File format is a plain PHP file returning an array, so OPcache can cache it and a
 * future `config:cache` can emit the same format.
 *
 * @api
 */
final readonly class Configuration
{
    /**
     * @param array<array-key, mixed> $items
     * @param array<string, string>   $variables      the process environment merged with .env, used to resolve EnvSecret references
     * @param bool                    $resolveSecrets false while building, so a missing production secret cannot fail `trunk build`
     */
    public function __construct(private array $items = [], private array $variables = [], private bool $resolveSecrets = true) {}

    /**
     * @param non-empty-string $path explicit file path; nothing is scanned
     */
    /**
     * @param array<string, string> $variables
     */
    public static function fromFile(string $path, array $variables = []): self
    {
        if (!is_file($path)) {
            throw new ConfigurationException(\sprintf('Configuration file "%s" does not exist.', $path));
        }

        $items = require $path;

        if (!\is_array($items)) {
            throw new ConfigurationException(\sprintf('Configuration file "%s" must return an array.', $path));
        }

        return new self($items, $variables);
    }

    public function has(string $key): bool
    {
        return $this->lookup($key)[0];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        [$found, $value] = $this->lookup($key);

        return $found ? $this->resolved($value) : $default;
    }

    /**
     * Like `get()` but a missing key is an error.
     */
    public function value(string $key): mixed
    {
        return $this->required($key);
    }

    public function string(string $key): string
    {
        $value = $this->required($key);

        return \is_string($value) ? $value : throw $this->wrongType($key, 'string');
    }

    public function int(string $key): int
    {
        $value = $this->required($key);

        return \is_int($value) ? $value : throw $this->wrongType($key, 'int');
    }

    public function bool(string $key): bool
    {
        $value = $this->required($key);

        return \is_bool($value) ? $value : throw $this->wrongType($key, 'bool');
    }

    /**
     * @return array<array-key, mixed>
     */
    public function array(string $key): array
    {
        $value = $this->required($key);

        return \is_array($value) ? $value : throw $this->wrongType($key, 'array');
    }

    private function required(string $key): mixed
    {
        [$found, $value] = $this->lookup($key);

        return $found ? $this->resolved($value) : throw new ConfigurationException(\sprintf('Configuration key "%s" is not defined.', $key));
    }

    private function wrongType(string $key, string $expected): ConfigurationException
    {
        return new ConfigurationException(\sprintf('Configuration key "%s" must be of type %s.', $key, $expected));
    }

    /**
     * Replaces EnvSecret references (at any depth) with their values from the environment.
     */
    private function resolved(mixed $value): mixed
    {
        if (!$this->resolveSecrets) {
            return $value;
        }

        if ($value instanceof EnvSecret) {
            return $value->resolve($this->variables);
        }

        if (\is_array($value)) {
            return array_map($this->resolved(...), $value);
        }

        return $value;
    }

    /**
     * @return array{bool, mixed}
     */
    private function lookup(string $key): array
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return [false, null];
            }

            $value = $value[$segment];
        }

        return [true, $value];
    }
}
