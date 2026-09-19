<?php

declare(strict_types=1);

namespace Trunk\Http\Routing;

use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Trunk\Http\Pipeline\Pipeline;
use Trunk\Router\Matcher\MatchResult;

/**
 * Final handler: resolves the matched controller from the container and calls it with the
 * pre-planned arguments. The class and method come from validated build output.
 */
final readonly class ControllerDispatcher implements RequestHandlerInterface
{
    public function __construct(
        private ContainerInterface $container,
        private ArgumentResolver $arguments = new ArgumentResolver(),
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $match = $request->getAttribute(RoutingMiddleware::ATTRIBUTE);

        if (!$match instanceof MatchResult || $match->handler === null) {
            throw new LogicException('No matched route found: RoutingMiddleware must run before the dispatcher.');
        }

        // Route and group middleware run here, after routing and before the controller, inside the
        // global pipeline and the error handler.
        return $match->middleware === [] ? $this->invoke($match, $request) : new Pipeline($match->middleware, $this->container, new class ($this, $match) implements RequestHandlerInterface {
            public function __construct(private readonly ControllerDispatcher $dispatcher, private readonly MatchResult $match) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->dispatcher->invoke($this->match, $request);
            }
        })->handle($request);
    }

    /**
     * @internal
     */
    public function invoke(MatchResult $match, ServerRequestInterface $request): ResponseInterface
    {
        if ($match->handler === null) {
            throw new LogicException('The matched route has no handler.');
        }

        [$class, $method] = $match->handler;
        $controller = $this->container->get($class);

        if (!\is_object($controller) || !method_exists($controller, $method)) {
            throw new LogicException(\sprintf('The container did not return a controller providing %s::%s().', $class, $method));
        }

        $response = $controller->{$method}(...$this->arguments->resolve($match->arguments, $request, $match->params));

        return $response instanceof ResponseInterface
            ? $response
            : throw new LogicException(\sprintf('%s::%s() must return a %s, %s returned.', $class, $method, ResponseInterface::class, get_debug_type($response)));
    }
}
