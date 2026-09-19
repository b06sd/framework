<?php

declare(strict_types=1);

namespace Trunk\Foundation;

use Trunk\Foundation\Exception\ConfigurationException;

/**
 * A reference to a secret held in the environment (a database password, an API key), not the secret
 * itself. Config files create it with `$runtime->secret('DB_PASSWORD')`. `trunk build` writes only
 * this reference into build/config.php; the value is looked up in the real environment (or .env) each
 * time the process starts, so build artifacts never contain credentials.
 *
 * @api
 */
final readonly class EnvSecret
{
    public function __construct(
        public string $name,
        /** A non-secret fallback used when the variable is not set (for example an empty local password). */
        public ?string $default = null,
    ) {
        if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $name) !== 1) {
            throw new ConfigurationException('A secret is named after an environment variable: capital letters, digits and underscores, e.g. DB_PASSWORD.');
        }
    }

    /**
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties): self
    {
        $name = $properties['name'] ?? '';
        $default = $properties['default'] ?? null;

        return new self(\is_string($name) ? $name : '', \is_string($default) ? $default : null);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'value' => '[secret]'];
    }

    /**
     * @param array<string, string> $variables the process environment merged with .env
     */
    public function resolve(array $variables): string
    {
        return $variables[$this->name] ?? $this->default ?? throw new ConfigurationException(\sprintf('The secret %s is not set. Define it in the environment (or in .env for local development).', $this->name));
    }
}
