<?php

declare(strict_types=1);

namespace Trunk\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Trunk\Http\Security\SecurityHeaders;

/**
 * Applies the `security_headers` policy from config/http.php to the responses of the routes it wraps,
 * whether or not the kernel-wide `enabled` switch is on. Use it as route or group middleware for a
 * section that needs the policy while the rest of the site does not. See `SecurityHeaders`.
 *
 * @api
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** @internal wired by the container */
    public function __construct(private SecurityHeaders $headers) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->headers->apply($handler->handle($request), $request->getUri()->getScheme() === 'https');
    }
}
