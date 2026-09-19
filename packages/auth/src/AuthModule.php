<?php

declare(strict_types=1);

namespace Trunk\Auth;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Trunk\Auth\Authorization\Ability;
use Trunk\Auth\Authorization\Gate;
use Trunk\Auth\Authorization\Policy;
use Trunk\Auth\Csrf\Csrf;
use Trunk\Auth\Password\NativePasswordHasher;
use Trunk\Auth\Password\PasswordHasher;
use Trunk\Auth\Session\ConfiguredSessionStore;
use Trunk\Auth\Session\Session;
use Trunk\Auth\Session\SessionStore;
use Trunk\Auth\Settings\AuthSettings;
use Trunk\Auth\Settings\PasswordSettings;
use Trunk\Auth\Settings\SessionSettings;
use Trunk\Auth\Settings\SettingsFactory;
use Trunk\Auth\Settings\ThrottleSettings;
use Trunk\Auth\Settings\TokenSettings;
use Trunk\Auth\Settings\UserSettings;
use Trunk\Auth\Token\DatabaseTokenStore;
use Trunk\Auth\Token\TokenStore;
use Trunk\Auth\User\DatabaseUserProvider;
use Trunk\Auth\User\UserProvider;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Build\BuildContribution;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Container\Definition\TaggedReference;
use Trunk\Container\Lifetime;
use Trunk\Contracts\BuildContributor;
use Trunk\Contracts\Clock;
use Trunk\Contracts\Module;
use Trunk\Contracts\ModuleDependencies;
use Trunk\Database\DatabaseModule;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Diagnostics\DiagnosticsModule;
use Trunk\Http\HttpModule;
use Trunk\Support\SystemClock;

/**
 * Registers authentication and authorization. Inject `Auth` to sign users in and out, `Gate` to
 * check permissions, `TokenManager` to issue API tokens, and use the middleware on the routes that
 * need them (`SessionMiddleware`, `CsrfMiddleware`, `RequireLogin`, `RequireToken`). `trunk build`
 * validates config/auth.php and the declared policies.
 *
 * @api
 */
final class AuthModule implements Module, BuildContributor, ModuleDependencies
{
    public function requires(): array
    {
        return [HttpModule::class, DatabaseModule::class, DiagnosticsModule::class];
    }

    public function after(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void
    {
        $builder->service(SettingsFactory::class, SettingsFactory::class);
        $builder->factory(AuthSettings::class, [SettingsFactory::class, 'settings'], [new Reference(Configuration::class)]);
        $builder->factory(PasswordSettings::class, [SettingsFactory::class, 'password'], [new Reference(AuthSettings::class)]);
        $builder->factory(UserSettings::class, [SettingsFactory::class, 'users'], [new Reference(AuthSettings::class)]);
        $builder->factory(SessionSettings::class, [SettingsFactory::class, 'session'], [new Reference(AuthSettings::class)]);
        $builder->factory(TokenSettings::class, [SettingsFactory::class, 'tokens'], [new Reference(AuthSettings::class)]);
        $builder->factory(ThrottleSettings::class, [SettingsFactory::class, 'throttle'], [new Reference(AuthSettings::class)]);
        $builder->bindDefault(Clock::class, SystemClock::class);
        $builder->bindDefault(SessionStore::class, ConfiguredSessionStore::class);
        $builder->scoped(Session::class);
        $builder->scoped(Auth::class);
        $builder->scoped(Csrf::class);
        $builder->service(Gate::class, Gate::class, [new Reference(Auth::class), new TaggedReference('auth.policy'), new TaggedReference('auth.ability')], Lifetime::Scoped);
        $builder->bindDefault(TokenStore::class, DatabaseTokenStore::class);
        $builder->bindDefault(PasswordHasher::class, NativePasswordHasher::class);
        $builder->bindDefault(UserProvider::class, DatabaseUserProvider::class);
    }

    public function boot(ContainerInterface $container): void {}

    public function plan(BuildContext $context): BuildContribution
    {
        try {
            AuthSettings::fromConfiguration($context->configuration);
        } catch (InvalidArgumentException $e) {
            throw new CompilationException(array_map(static fn(string $line): string => 'config/auth.php: ' . $line, explode("\n", $e->getMessage())));
        }

        $errors = $this->policyProblems($context);

        if ($errors !== []) {
            throw new CompilationException($errors);
        }

        return new BuildContribution();
    }

    /**
     * Every service tagged `auth.policy` must be a `Policy` and every `auth.ability` an `Ability`, or the
     * build fails (not the first request that needs them).
     *
     * @return list<string>
     */
    private function policyProblems(BuildContext $context): array
    {
        $builder = new ContainerBuilder();

        foreach ($context->manifest->modules as $class) {
            if (class_exists($class)) {
                new $class()->register($builder);
            }
        }

        $errors = [];

        foreach ($builder->tags()['auth.policy'] ?? [] as $id) {
            if (!is_subclass_of($id, Policy::class)) {
                $errors[] = \sprintf('%s is tagged "auth.policy" but does not implement %s.', $id, Policy::class);
            }
        }

        foreach ($builder->tags()['auth.ability'] ?? [] as $id) {
            if (!is_subclass_of($id, Ability::class)) {
                $errors[] = \sprintf('%s is tagged "auth.ability" but does not implement %s.', $id, Ability::class);
            }
        }

        return $errors;
    }
}
