# Rate limiting

`trunk package:install rate-limit`, then `trunk rate-limit:table && trunk migrate`. A database-backed counter (correct across requests, processes and servers, unlike anything kept in memory) plus a ready-made middleware for the common case: limit a route or group by client address.

## The ready-made middleware

```php
$api->group('/api', function (RouteCollector $routes): void {
    $routes->get('/orders', [OrderController::class, 'index']);
}, middleware: [RateLimitMiddleware::class]);
```

Every route that lists `RateLimitMiddleware` shares one budget per client address (`RATE_LIMIT_MAX` requests per `RATE_LIMIT_WINDOW` seconds, default 60 per 60; set them in `.env` or `config/ratelimit.php`). Over the limit, the request never reaches the controller: it is a `429` with `Retry-After` and the standard error body (`{"error":{"code":"TOO_MANY_REQUESTS",...}}`). Behind a proxy, set `http.trusted_proxies` so the address used is the real client, not the proxy.

## A different limit, or a different key

Attaching the same middleware to two route groups gives them the *same* shared budget. For separate budgets, a stricter limit on one route, or a key that is not the client address (a user id, an API key), inject `RateLimiter` directly and call it yourself:

```php
final readonly class OrderController
{
    public function __construct(private RateLimiter $limiter, /* ... */) {}

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $key = hash('sha256', 'orders|' . $this->auth->user()->authId());
        $this->limiter->assertBelow($key, limit: 10, window: 60);   // throws a 429
        $this->limiter->hit($key, window: 60);
        // ...
    }
}
```

`assertBelow()` throws before anything else in the request runs; `hit()` records the attempt. Both take the limit and window per call, so one injected `RateLimiter` covers as many different limits as you need.

## Maintenance

`trunk rate-limit:prune` (run it from cron) deletes finished counter windows; not required for correctness, a window that has passed is never counted again, only to keep the table small.

Related: [HTTP](http.md) (security headers, CORS), [Auth](auth.md) (login throttling uses the same atomic-counter approach, kept separate since it is tied to auth's own tables).
