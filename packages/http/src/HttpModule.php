<?php

declare(strict_types=1);

namespace Trunk\Http;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Build\BuildContribution;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Container\Definition\TaggedReference;
use Trunk\Contracts\BuildContributor;
use Trunk\Contracts\Module;
use Trunk\Foundation\Configuration;
use Trunk\Http\Build\HttpArtifactBuilder;
use Trunk\Http\Error\ErrorRenderers;
use Trunk\Http\Factory\HttpFactory;
use Trunk\Http\Response\ResponseBuilder;
use Trunk\Http\Security\SecurityHeaders;
use Trunk\Http\Security\SecurityHeadersFactory;
use Trunk\Http\Server\RequestLimits;
use Trunk\Http\Server\TrustedProxies;

/**
 * Makes the PSR-17 factories available to controllers and services through the container.
 *
 * @api
 */
final class HttpModule implements Module, BuildContributor
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->providedInScope(ServerRequestInterface::class);
        $builder->service(HttpFactory::class, HttpFactory::class);
        $builder->service(SecurityHeadersFactory::class, SecurityHeadersFactory::class);
        $builder->factory(SecurityHeaders::class, [SecurityHeadersFactory::class, 'create'], [new Reference(Configuration::class)]);
        $builder->service(ErrorRenderers::class, ErrorRenderers::class, [new TaggedReference('http.error_renderer')]);
        $builder->service(ResponseBuilder::class, ResponseBuilder::class, [new Reference(ResponseFactoryInterface::class)]);

        foreach ([
            RequestFactoryInterface::class,
            ResponseFactoryInterface::class,
            ServerRequestFactoryInterface::class,
            StreamFactoryInterface::class,
            UploadedFileFactoryInterface::class,
            UriFactoryInterface::class,
        ] as $interface) {
            $builder->alias($interface, HttpFactory::class);
        }
    }

    public function boot(ContainerInterface $container): void {}

    public function plan(BuildContext $context): BuildContribution
    {
        try {
            RequestLimits::fromConfiguration($context->configuration);
            TrustedProxies::fromConfiguration($context->configuration);
            SecurityHeaders::configured($context->configuration);
        } catch (InvalidArgumentException $e) {
            throw new CompilationException(['config/http.php: ' . $e->getMessage()]);
        }

        $builder = new HttpArtifactBuilder();
        $plan = $builder->plan($context->manifest);

        return new BuildContribution($plan->roots(), [static fn(string $directory) => $builder->writeArtifacts($plan, $directory)]);
    }
}
