<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Records the order in which route middleware ran (outermost first) in X-Order.
 */
final class OrderBMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $existing = $response->getHeaderLine('X-Order');

        return $response->withHeader('X-Order', $existing === '' ? 'B' : $existing . ',B');
    }
}
