<?php

declare(strict_types=1);

namespace Trunk\Validation;

use Psr\Container\ContainerInterface;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Build\BuildContribution;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Contracts\BuildContributor;
use Trunk\Contracts\Module;
use Trunk\Foundation\Configuration;
use Trunk\Http\Routing\InputBinder;
use Trunk\Http\Server\JsonBody;
use Trunk\Router\Compiler\RouteArtifactWriter;
use Trunk\Router\Definition\ArgumentKind;
use Trunk\Router\Exception\RouteCompilationException;
use Trunk\Support\FileWriter;
use Trunk\Validation\Compiler\CodeGenerator;
use Trunk\Validation\Compiler\ConfiguredPlans;
use Trunk\Validation\Compiler\PlanBuilder;
use Trunk\Validation\Http\RequestValidator;

/**
 * Registers `Validator` and `RequestValidator` (which also binds `#[Body]` and `#[Query]` handler
 * parameters). Request classes live in `app/Requests` (listed in config/validation.php) or are named by
 * such a parameter. `trunk build` checks every one of them, reports mistakes as build errors, and
 * writes build/validation.php; production runs only that generated file.
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
        $builder->alias(InputBinder::class, RequestValidator::class);
    }

    public function boot(ContainerInterface $container): void {}

    public function plan(BuildContext $context): BuildContribution
    {
        $classes = $context->configuration->has('validation.requests')
            ? array_values(array_filter($context->configuration->array('validation.requests'), is_string(...)))
            : [];
        $plans = new PlanBuilder()->all([...$classes, ...$this->inputClasses($context)]);
        $source = new CodeGenerator()->generate($plans);

        return new BuildContribution([], [static function (string $directory) use ($source): void {
            new FileWriter()->write($directory . '/validation.php', $source);
        }]);
    }

    /**
     * The request classes named by `#[Body]` and `#[Query]` handler parameters. A class that says it is
     * read from somewhere else (`#[From]`) cannot be used both ways.
     *
     * @return list<class-string>
     *
     * @throws CompilationException
     */
    private function inputClasses(BuildContext $context): array
    {
        try {
            $table = new RouteArtifactWriter()->compile($context->manifest);
        } catch (RouteCompilationException) {
            return [];   // the http build reports broken routes
        }

        $classes = [];
        $errors = [];
        $reader = new PlanBuilder();

        foreach ($table->routes as $route) {
            foreach ($route['args'] as $argument) {
                $query = $argument['kind'] === ArgumentKind::Query->value;

                if ((!$query && $argument['kind'] !== ArgumentKind::Body->value) || !class_exists($argument['type'])) {
                    continue;
                }

                $declared = $reader->plan($argument['type'])->source;

                if ($declared !== null && ($declared === Source::Query) !== $query) {
                    $errors[] = \sprintf('Route %s: %s is #[From(Source::%s)] but parameter $%s is #[%s]. Fix: remove #[From] or use #[%s].', $route['pattern'], $argument['type'], $declared->name, $argument['name'], $query ? 'Query' : 'Body', $query ? 'Body' : 'Query');
                }

                $classes[] = $argument['type'];
            }
        }

        if ($errors !== []) {
            throw new CompilationException($errors);
        }

        return array_values(array_unique($classes));
    }
}
