<?php

declare(strict_types=1);

namespace Trunk\Tusk;

use Psr\Container\ContainerInterface;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Build\BuildContribution;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Contracts\BuildContributor;
use Trunk\Contracts\Module;
use Trunk\Foundation\Configuration;
use Trunk\Tusk\Compiler\TemplateBuilder;
use Trunk\Tusk\Exception\TemplateBuildException;
use Trunk\Tusk\Loader\ConfiguredTemplateLoader;
use Trunk\Tusk\Loader\TemplateLoader;

/**
 * Registers the Tusk renderer. Requires the `views.*` configuration keys (see ConfiguredTemplateLoader).
 *
 * @api
 */
final class TuskModule implements Module, BuildContributor
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->service(TemplateLoader::class, ConfiguredTemplateLoader::class, [new Reference(Configuration::class)]);
        $builder->service(Renderer::class, Renderer::class, [new Reference(TemplateLoader::class)]);
    }

    public function boot(ContainerInterface $container): void {}

    /**
     * Compiles every template under `views.paths` (skipped when the application has no `views` config).
     */
    public function plan(BuildContext $context): BuildContribution
    {
        if (!$context->configuration->has('views.paths')) {
            return new BuildContribution();
        }

        $paths = array_values(array_filter($context->configuration->array('views.paths'), is_string(...)));
        $builder = new TemplateBuilder();

        try {
            $compiled = $builder->plan($paths);
        } catch (TemplateBuildException $e) {
            throw new CompilationException($e->errors);
        }

        return new BuildContribution([], [static fn(string $directory) => $builder->write($compiled, $directory)]);
    }
}
