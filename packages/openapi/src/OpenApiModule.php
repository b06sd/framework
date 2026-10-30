<?php

declare(strict_types=1);

namespace Trunk\OpenApi;

use Psr\Container\ContainerInterface;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Build\BuildContribution;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\ConfigValue;
use Trunk\Container\Definition\Reference;
use Trunk\Contracts\BuildContributor;
use Trunk\Contracts\Module;
use Trunk\Contracts\ModuleDependencies;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Runtime;
use Trunk\Http\HttpModule;
use Trunk\Http\Response\ResponseBuilder;
use Trunk\OpenApi\Exception\OpenApiException;
use Trunk\OpenApi\Http\OpenApiController;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;
use Trunk\Support\FileWriter;
use Trunk\Validation\ValidationModule;

/**
 * An OpenAPI 3.1 document of the routes, at GET /openapi.json, with Swagger UI at GET /docs (both only
 * where `openapi.serve` is on: local development by default). `trunk build` checks that the document
 * can be generated and writes build/openapi.json; `trunk openapi` writes it to a file.
 *
 * @api
 */
final class OpenApiModule implements Module, RouteProvider, BuildContributor, ModuleDependencies
{
    public function requires(): array
    {
        return [HttpModule::class, ValidationModule::class];
    }

    public function after(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void
    {
        $builder->service(OpenApi::class, OpenApi::class, [new Reference(Runtime::class), new Reference(Configuration::class)]);
        $builder->service(OpenApiController::class, OpenApiController::class, [
            new Reference(OpenApi::class),
            new Reference(ResponseBuilder::class),
            new ConfigValue('openapi.serve', 'bool'),
        ]);
    }

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/openapi.json', [OpenApiController::class, 'spec'], 'openapi.spec');
        $routes->get('/docs', [OpenApiController::class, 'ui'], 'openapi.docs');
    }

    public function plan(BuildContext $context): BuildContribution
    {
        try {
            $json = OpenApi::encode(new OpenApi($context->runtime, $context->configuration)->generate($context->manifest));
        } catch (OpenApiException $e) {
            throw new CompilationException(array_map(static fn(string $line): string => 'OpenAPI: ' . $line, explode("\n", $e->getMessage())));
        }

        return new BuildContribution([], [static function (string $directory) use ($json): void {
            new FileWriter()->write($directory . '/openapi.json', $json);
        }]);
    }
}
