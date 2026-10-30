<?php

declare(strict_types=1);

namespace Trunk\Auth\Jwt;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Trunk\Auth\AuthModule;
use Trunk\Auth\Settings\SettingsFactory;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Build\BuildContribution;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Contracts\BuildContributor;
use Trunk\Contracts\Clock;
use Trunk\Contracts\Module;
use Trunk\Contracts\ModuleDependencies;
use Trunk\Foundation\Configuration;

/**
 * Verifies JWTs from your identity provider: put `RequireJwt` on the routes that accept them, or
 * inject `JwtVerifier`. Settings in config/jwt.php; `trunk build` checks them.
 *
 * @api
 */
final class JwtModule implements Module, BuildContributor, ModuleDependencies
{
    public function requires(): array
    {
        return [AuthModule::class];
    }

    public function after(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void
    {
        $builder->factory(JwtSettings::class, [SettingsFactory::class, 'jwt'], [new Reference(Configuration::class)]);
        $builder->bindDefault(KeySource::class, HttpKeySource::class);
        $builder->service(KeySet::class, KeySet::class, [new Reference(JwtSettings::class), new Reference(KeySource::class), new Reference(Clock::class)]);
        $builder->service(JwtVerifier::class, JwtVerifier::class, [new Reference(JwtSettings::class), new Reference(KeySet::class), new Reference(Clock::class)]);
        $builder->bindDefault(JwtUserResolver::class, ConfiguredJwtUsers::class);
    }

    public function boot(ContainerInterface $container): void {}

    public function plan(BuildContext $context): BuildContribution
    {
        try {
            JwtSettings::fromConfiguration($context->configuration);
        } catch (InvalidArgumentException $e) {
            throw new CompilationException(array_map(static fn(string $line): string => 'config/jwt.php: ' . $line, explode("\n", $e->getMessage())));
        }

        return new BuildContribution();
    }
}
