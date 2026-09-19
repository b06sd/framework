# HTTP

## Routing

Routes live in files you load from a module (`RouteProvider::routes(RouteCollector)`). Methods: `get post put patch delete any map(['GET','POST'], ...)`. The arguments are the pattern, the handler `[Controller::class, 'method']`, an optional route name and optional `middleware:`.

```php
$routes->get('/users/{id:int}', [UserController::class, 'show'], 'users.show');
$routes->get('/list/{page?}', [ListController::class, 'index']);            // optional parameter
$routes->get('/files/{name:slug}', [FileController::class, 'show']);
$routes->post('/orders', [OrderController::class, 'store'], middleware: [Audit::class]);
$routes->group('/admin', static function (RouteCollector $admin): void {
    $admin->get('/users', [AdminController::class, 'users']);
}, middleware: [AdminOnly::class]);                                          // groups nest; a group needs a prefix
```

Constraints: `int`, `alpha`, `alnum`, `slug`, `uuid`, `any` (matches slashes), or a single character class such as `[a-z]+` or `\d{2,4}`. Free-form regular expressions are refused. A parameter never contains an encoded slash.

**Handler arguments are bound by name and type**: the request (`ServerRequestInterface $request`), route parameters (`int $id` converts and rejects overflow), and defaults. An argument that cannot be bound fails the **build** (and `trunk route:list` shows how each one is bound). Duplicate routes fail the build.

Behaviour you get for free: `HEAD` runs the `GET` route (no body), a known path with the wrong method is `405` with an `Allow` header, and trailing slashes are not normalized (`/posts/` is a 404).

## Controllers

Plain classes, no base class, dependencies through the constructor. Return a PSR-7 `ResponseInterface`:

```php
final readonly class InvoiceController
{
    public function __construct(private ResponseBuilder $responses, private Invoices $invoices) {}

    public function show(int $id): ResponseInterface
    {
        return $this->responses->json($this->invoices->find($id) ?? throw HttpException::notFound());
    }
}
```

`ResponseBuilder` (any project with `http`): `html`, `text`, `json`, `noContent`, `redirect`. Everything sets `X-Content-Type-Options: nosniff`; `json` escapes `<`, `>`, `&` and quotes so it is safe to embed in HTML. **`redirect` accepts only local paths** (no host, no `//`, no backslash, no control characters) and throws `UnsafeRedirectException` otherwise. `Responder` (with `mvc`) offers the same plus `view()`.

Read a JSON body with the injected `JsonBody`: it enforces the content type (415), the size (413), the depth and strict decoding (400).

## Middleware

Standard PSR-15 middleware. Generate one with `trunk make:middleware Name`. Three places to attach it:

```php
// 1. global: a module implements MiddlewareProvider
public function middleware(MiddlewareCollector $middleware): void { $middleware->add(Timing::class); }

// 2. per route, 3. per group (see above): `middleware: [ClassA::class, ClassB::class]`
```

Order per request: global, error handling, routing, group/route middleware (outer group first), controller. Middleware classes are resolved from the container in the request scope, so they can take scoped services. The class must exist and implement `MiddlewareInterface` or the build fails.

## Errors

| You throw | Client sees |
| --- | --- |
| `HttpException::notFound()`, `new HttpException(422, 'message', headers: [...], code: 'MY_CODE')` | The status, a stable `code`, the request id (message only for `PublicError`) |
| anything else | `500`, code `INTERNAL_ERROR`, a request id, **nothing else** |

```json
{"error":{"code":"NOT_FOUND","message":"Not Found","requestId":"req_01M2..."}}
```

In `local` with `APP_DEBUG=1` the response adds the exception, the source lines (for a template failure, the template lines), a trace without arguments and the request with secret headers redacted. Force a format with `errors.format` (`auto`, `json`, `html`, `text`). Your own error pages: `resources/views/errors/404.tusk.php` and `error.tusk.php`.

## Limits (`config/http.php`)

`max_body_bytes` (2 MB, 413 by header and while streaming), `max_files`, `max_file_bytes`, `max_json_depth`, `max_uri_bytes` (8192, 414). Malformed or conflicting `Content-Length`/`Transfer-Encoding` is 400. A malformed `Host` (userinfo, path, port 0 or above 65535, spaces) is 400.

## Behind a proxy

`X-Forwarded-Proto/Host/Port/For` are **ignored unless the TCP peer is listed** in `http.trusted_proxies` (IPs or CIDR ranges, default empty). Then scheme, host and port come from the value the nearest proxy appended, and the client address (request attribute `client_ip`) is the first `X-Forwarded-For` entry, read right to left, that is not itself a trusted proxy. RFC 7239 `Forwarded` is never read. Never list `0.0.0.0/0`.

## Security headers

Enabled by the `config/http.php` that `trunk new` publishes:

```php
'security_headers' => ['enabled' => true, 'frame_options' => 'DENY', 'referrer_policy' => 'strict-origin-when-cross-origin',
    'content_security_policy' => "frame-ancestors 'none'; base-uri 'self'; form-action 'self'", 'hsts' => 31536000, ...],
```

Applied to every response (error pages included), never overwriting a header a handler set, with `Strict-Transport-Security` only on https requests. A value can be `false` to leave a header out. `SecurityHeadersMiddleware` applies the same policy to one route group when the global switch is off. The default CSP is only the always-safe directives; a full `default-src`/`script-src` policy depends on your pages, so set it yourself.

## Health and metrics

`trunk package:install health` adds `/health/live` (never touches dependencies), `/health/ready` (runs the checks tagged `trunk.health_check`, such as the database; production shows only up/down) and `/metrics` (Prometheus text, off until `health.metrics_token` is set, then `Authorization: Bearer <token>`; needs the `observability` capability).
