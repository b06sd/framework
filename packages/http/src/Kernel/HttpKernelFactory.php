<?php

declare(strict_types=1);

namespace Trunk\Http\Kernel;

use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Trunk\Application\Application;
use Trunk\Application\Phase;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\Scopable;
use Trunk\Contracts\Module;
use Trunk\Error\ExceptionHandler;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Http\Emitter\ResponseEmitter;
use Trunk\Http\Error\ErrorRenderers;
use Trunk\Http\Factory\HttpFactory;
use Trunk\Http\Middleware\ErrorHandlingMiddleware;
use Trunk\Http\Pipeline\MiddlewareCollector;
use Trunk\Http\Pipeline\MiddlewareProvider;
use Trunk\Http\Pipeline\Pipeline;
use Trunk\Http\Routing\ControllerDispatcher;
use Trunk\Http\Routing\RouteMiddlewareValidator;
use Trunk\Http\Routing\RoutingMiddleware;
use Trunk\Http\Security\SecurityHeaders;
use Trunk\Http\Server\RequestLimits;
use Trunk\Http\Server\ServerRequestCreator;
use Trunk\Http\Server\TrustedProxies;
use Trunk\Lifecycle\LifecycleManager;
use Trunk\Logging\ContextHolder;
use Trunk\Observability\Metrics;
use Trunk\Observability\Tracer;
use Trunk\Router\Compiler\RouteCompiler;
use Trunk\Router\Compiler\RouteTable;
use Trunk\Router\Definition\RouteProvider;
use Trunk\Router\Matcher\Matcher;

/**
 * Assembles an HttpKernel from a booted Application. The caller picks the mode explicitly:
 * `development()` collects routes and middleware from modules, `compiled()` loads build artifacts.
 */
final readonly class HttpKernelFactory
{
    public function __construct(
        private ResponseEmitter $emitter = new ResponseEmitter(),
        private ?ServerRequestCreator $creator = null,
        private ?LoggerInterface $logger = null,
    ) {}

    public function development(Application $application, ModuleManifest $manifest): HttpKernel
    {
        $routeProviders = [];
        $middleware = new MiddlewareCollector();

        foreach ($manifest->modules as $class) {
            $module = new $class();
            $this->collect($module, $routeProviders, $middleware);
        }

        $table = new RouteCompiler()->compileProviders($routeProviders);
        $problems = new RouteMiddlewareValidator()->errors($table);

        if ($problems !== []) {
            throw new CompilationException($problems);
        }

        return $this->create($application, $table, $middleware->ids());
    }

    /**
     * @param string $directory build directory containing routes.php and pipeline.php
     */
    public function compiled(Application $application, string $directory): HttpKernel
    {
        $ids = require $directory . '/pipeline.php';

        if (!\is_array($ids) || !array_is_list($ids) || array_filter($ids, is_string(...)) !== $ids) {
            throw new LogicException('pipeline.php must return a list of middleware ids.');
        }

        return $this->create($application, RouteTable::fromFile($directory . '/routes.php'), $ids);
    }

    /**
     * @param list<RouteProvider> $routeProviders
     */
    private function collect(Module $module, array &$routeProviders, MiddlewareCollector $middleware): void
    {
        if ($module instanceof RouteProvider) {
            $routeProviders[] = $module;
        }

        if ($module instanceof MiddlewareProvider) {
            $module->middleware($middleware);
        }
    }

    /**
     * @param list<string> $middlewareIds
     */
    private function create(Application $application, RouteTable $routes, array $middlewareIds): HttpKernel
    {
        if ($application->phase() !== Phase::Booted) {
            throw new LogicException('The application must be booted before the HTTP kernel is created.');
        }

        $container = $application->container();

        if (!$container instanceof Scopable) {
            throw new LogicException('The application container must support scopes (Scopable).');
        }

        $runtime = $container->get(Runtime::class);
        $debug = $runtime instanceof Runtime && $runtime->debug;
        $handler = $this->service($container, ExceptionHandler::class) ?? new ExceptionHandler($this->logger ?? $this->containerLogger($container));
        $renderers = $this->service($container, ErrorRenderers::class);
        $configuration = $this->service($container, Configuration::class);
        $format = $configuration instanceof Configuration && $configuration->has('errors.format') && \is_string($configuration->get('errors.format')) ? $configuration->string('errors.format') : 'auto';
        $errors = new ErrorHandlingMiddleware(new HttpFactory(), $debug, null, $handler instanceof ExceptionHandler ? $handler : null, $renderers instanceof ErrorRenderers ? $renderers->all() : [], $format);
        $stack = [...$middlewareIds, $errors, new RoutingMiddleware(new Matcher($routes))];
        $contexts = $this->service($container, ContextHolder::class);
        $lifecycle = $this->service($container, LifecycleManager::class);
        $metrics = $this->service($container, Metrics::class);
        $tracer = $this->service($container, Tracer::class);

        return new HttpKernel(
            $container,
            static fn(ContainerInterface $scope): Pipeline => new Pipeline($stack, $scope, new ControllerDispatcher($scope)),
            $errors,
            $this->emitter,
            $this->creator ?? new ServerRequestCreator(...$this->creatorSettings($configuration)),
            $contexts instanceof ContextHolder ? $contexts : null,
            $lifecycle instanceof LifecycleManager ? $lifecycle : null,
            true,
            $metrics instanceof Metrics ? $metrics : null,
            $tracer instanceof Tracer ? $tracer : null,
            $configuration instanceof Configuration ? SecurityHeaders::fromConfiguration($configuration) : null,
        );
    }

    /**
     * @return array{RequestLimits, TrustedProxies}
     */
    private function creatorSettings(?object $configuration): array
    {
        return $configuration instanceof Configuration ? [RequestLimits::fromConfiguration($configuration), TrustedProxies::fromConfiguration($configuration)] : [new RequestLimits(), new TrustedProxies()];
    }

    private function service(ContainerInterface $container, string $id): ?object
    {
        $service = $container->has($id) ? $container->get($id) : null;

        return \is_object($service) ? $service : null;
    }

    private function containerLogger(ContainerInterface $container): ?LoggerInterface
    {
        $logger = $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;

        return $logger instanceof LoggerInterface ? $logger : null;
    }
}
