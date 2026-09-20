<?php

declare(strict_types=1);

namespace Trunk\Validation;

use Psr\Container\ContainerInterface;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Build\BuildContribution;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Contracts\BuildContributor;
use Trunk\Contracts\Module;
use Trunk\Foundation\Configuration;
use Trunk\Http\Server\JsonBody;
use Trunk\Support\FileWriter;
use Trunk\Validation\Compiler\CodeGenerator;
use Trunk\Validation\Compiler\ConfiguredPlans;
use Trunk\Validation\Compiler\PlanBuilder;
use Trunk\Validation\Http\RequestValidator;

/**
 * Registers `Validator` and `RequestValidator`. Request classes live in `app/Requests` (listed in
 * config/validation.php). `trunk build` checks every one of them, reports mistakes as build errors,
 * and writes build/validation.php; production runs only that generated file.
 *
 * @api
 */
final class ValidationModule implements Module, BuildContributor
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->service(Plans::class, ConfiguredPlans::class, [new Reference(Configuration::class)]);
        $builder->service(Validator::class, Validator::class, [new Reference(Plans::class)]);
        $builder->service(RequestValidator::class, RequestValidator::class, [new Reference(Validator::class), new Reference(JsonBody::class), new Reference(Plans::class)]);
    }

    public function boot(ContainerInterface $container): void {}

    public function plan(BuildContext $context): BuildContribution
    {
        $classes = $context->configuration->has('validation.requests')
            ? array_values(array_filter($context->configuration->array('validation.requests'), is_string(...)))
            : [];
        $source = new CodeGenerator()->generate(new PlanBuilder()->all($classes));

        return new BuildContribution([], [static function (string $directory) use ($source): void {
            new FileWriter()->write($directory . '/validation.php', $source);
        }]);
    }
}
