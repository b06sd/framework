<?php

declare(strict_types=1);

namespace Trunk\Foundation;

/**
 * Immutable description of the process environment. Debug can never be enabled in production.
 *
 * @api
 */
final readonly class Runtime
{
    public bool $debug;

    /**
     * @param array<string, string> $variables settings from the real environment and the project's .env file
     */
    public function __construct(
        public Environment $environment,
        bool $debug,
        public string $basePath,
        public array $variables = [],
    ) {
        $this->debug = $debug && $this->environment !== Environment::Production;
    }

    /**
     * A setting from the environment or .env (config files use this: `$runtime->variable('APP_NAME', 'My App')`).
     */
    public function variable(string $name, ?string $default = null): ?string
    {
        return $this->variables[$name] ?? $default;
    }

    /**
     * A secret from the environment. Unlike `variable()`, which is read while `trunk build` runs and
     * compiled into build/config.php, this returns a reference: the value is resolved when the
     * application starts and never written to a build artifact. Use it for passwords, tokens and keys.
     *
     * @param string|null $default a non-secret fallback (for example an empty local password)
     */
    public function secret(string $name, ?string $default = null): EnvSecret
    {
        return new EnvSecret($name, $default);
    }
}
