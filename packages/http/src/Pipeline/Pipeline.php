<?php

declare(strict_types=1);

namespace Trunk\Http\Pipeline;

use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs middleware in order, then the final handler. String entries are container ids resolved
 * only when their turn comes, so middleware that short-circuits never instantiates the rest.
 */
final readonly class Pipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface|string> $middleware
     */
    public function __construct(
        private array $middleware,
        private ContainerInterface $container,
        private RequestHandlerInterface $final,
        private int $position = 0,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $entry = $this->middleware[$this->position] ?? null;

        if ($entry === null) {
            return $this->final->handle($request);
        }

        $middleware = \is_string($entry) ? $this->container->get($entry) : $entry;

        if (!$middleware instanceof MiddlewareInterface) {
            throw new LogicException(\sprintf('"%s" did not resolve to a PSR-15 middleware.', $entry));
        }

        return $middleware->process($request, new self($this->middleware, $this->container, $this->final, $this->position + 1));
    }
}
