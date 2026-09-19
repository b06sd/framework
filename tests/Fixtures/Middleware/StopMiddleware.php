<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Trunk\Http\Factory\HttpFactory;

/**
 * Short-circuits with 401 unless the request carries X-Pass.
 */
final class StopMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getHeaderLine('X-Pass') === '') {
            return new HttpFactory()->createResponse(401)->withHeader('X-Stopped', '1');
        }

        return $handler->handle($request);
    }
}
