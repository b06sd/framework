<?php

declare(strict_types=1);

namespace Trunk\Console\Scaffold;

use Trunk\Foundation\Capability\Capability;
use Trunk\Foundation\Capability\CapabilityCatalog;
use Trunk\Foundation\Capability\CapabilityResolver;

/**
 * A starting configuration, not a mode: every profile is just a list of modules (capabilities) plus
 * some files. Anything can be added or removed later by editing trunk.php.
 */
enum Profile: string
{
    case Api = 'api';
    case Web = 'web';
    case SelfContained = 'self-contained';
    case Cli = 'cli';
    case Worker = 'worker';

    /**
     * The capabilities this profile starts with (the project can add or remove any later).
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::Api => ['http', 'diagnostics'],
            self::Web => ['http', 'diagnostics', 'tusk', 'mvc'],
            self::SelfContained => ['http', 'diagnostics', 'tusk', 'mvc', 'cache'],
            self::Cli => ['diagnostics', 'console'],
            self::Worker => ['diagnostics', 'console', 'queue'],
        };
    }

    /**
     * The capabilities to enable, requirements first.
     *
     * @return list<Capability>
     */
    public function plan(): array
    {
        $catalog = new CapabilityCatalog(null);
        $resolver = new CapabilityResolver($catalog);
        $modules = [];
        $plan = [];

        foreach ($this->capabilities() as $id) {
            foreach ($resolver->plan($id, $modules) as $capability) {
                $plan[] = $capability;
                $modules = [...$modules, ...$capability->modules];
            }
        }

        return $plan;
    }

    /**
     * @return list<string> module class names in load order, the application's own module last
     */
    public function modules(): array
    {
        $modules = array_merge(...array_map(static fn(Capability $c): array => $c->modules, $this->plan()));

        return [...$modules, ...new CapabilityCatalog(null)->integrationModules($modules), 'App\\AppModule'];
    }

    public function hasHttp(): bool
    {
        return \in_array($this, [self::Api, self::Web, self::SelfContained], true);
    }

    public function hasApiRoutes(): bool
    {
        return $this === self::Api || $this === self::SelfContained;
    }

    public function hasWebRoutes(): bool
    {
        return $this === self::Web || $this === self::SelfContained;
    }

    public function hasConsole(): bool
    {
        return $this === self::Cli || $this === self::Worker;
    }

    public function description(): string
    {
        return match ($this) {
            self::Api => 'JSON API: HTTP, router, controllers, services. No view engine.',
            self::Web => 'Web application: HTTP, router, Tusk templates, controllers.',
            self::SelfContained => 'One deployable application: Tusk web UI and a JSON API together.',
            self::Cli => 'Command-line application: commands with the same DI, config and modules.',
            self::Worker => 'Queue worker: typed jobs, a database queue and `trunk queue:work`, no HTTP.',
        };
    }

    public static function fromName(string $name): self
    {
        return self::tryFrom($name) ?? throw new \Trunk\Contracts\Console\Exception\UsageException(\sprintf(
            'Unknown project type "%s". Choose one of: %s.',
            $name,
            implode(', ', array_map(static fn(self $p): string => $p->value, self::cases())),
        ));
    }
}
