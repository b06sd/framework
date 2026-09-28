<?php

declare(strict_types=1);

namespace Trunk\RateLimit\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Trunk\Http\Server\ServerRequestCreator;
use Trunk\RateLimit\RateLimiter;

/**
 * A ready-made limit any route or group can opt into with `middleware: [RateLimitMiddleware::class]`.
 * Counts requests per client address (the `client_ip` attribute; behind a proxy, set
 * `http.trusted_proxies` so that is the real client, not the proxy); every route that uses this
 * middleware shares one budget. Limit and window come from `config/ratelimit.php`
 * (`RATE_LIMIT_MAX`/`RATE_LIMIT_WINDOW`). For a different limit on a different route, or a different
 * key (a user id, an API key), inject `RateLimiter` directly instead.
 *
 * @api
 */
final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    /** @internal wired by the container, not part of the API */
    public function __construct(private RateLimiter $limiter, private int $max, private int $window) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $address = $request->getAttribute(ServerRequestCreator::CLIENT_IP_ATTRIBUTE);
        $key = hash('sha256', 'route|' . (\is_string($address) ? $address : 'unknown'));

        $this->limiter->assertBelow($key, $this->max, $this->window);
        $this->limiter->hit($key, $this->window);

        return $handler->handle($request);
    }
}
