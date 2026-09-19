<?php

declare(strict_types=1);

namespace Trunk\Mvc;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Contracts\Module;
use Trunk\Contracts\ModuleDependencies;
use Trunk\Http\HttpModule;
use Trunk\Http\Response\ResponseBuilder;
use Trunk\Tusk\Renderer;
use Trunk\Tusk\TuskModule;

/**
 * Registers the Responder. Add HttpModule and TuskModule to the manifest as well.
 *
 * @api
 */
final class MvcModule implements Module, ModuleDependencies
{
    public function requires(): array
    {
        return [HttpModule::class, TuskModule::class];
    }

    public function after(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void
    {
        $builder->service(TuskErrorRenderer::class, TuskErrorRenderer::class, [new Reference(Renderer::class)]);
        $builder->tag('http.error_renderer', TuskErrorRenderer::class);
        $builder->service(Responder::class, Responder::class, [
            new Reference(ResponseBuilder::class),
            new Reference(Renderer::class),
        ]);
    }

    public function boot(ContainerInterface $container): void {}
}
