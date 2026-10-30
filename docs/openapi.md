# OpenAPI

`trunk package:install openapi` (it needs `validation`). Trunk writes an [OpenAPI 3.1](https://spec.openapis.org/oas/v3.1.0) document of your API from the code you already have: the routes, the `#[Body]` and `#[Query]` request classes and their rules, and the authentication middleware. You add only what the code cannot say: what each handler returns.

## Describing an endpoint

```php
use Trunk\OpenApi\Attribute\Returns;
use Trunk\Router\Attribute\Body;
use Trunk\Router\Attribute\Query;

final readonly class OrderController
{
    /**
     * List orders.
     */
    #[Returns(200, OrderView::class, list: true)]
    public function index(#[Query] OrderFilters $filters): ResponseInterface { /* ... */ }

    /**
     * Place an order.
     *
     * Stock is reserved at once; payment follows.
     */
    #[Returns(201, OrderView::class)]
    #[Returns(409, description: 'Out of stock')]
    public function store(#[Body] PlaceOrder $order): ResponseInterface { /* ... */ }
}
```

| From the code | In the document |
| --- | --- |
| the route pattern and handler types (`{id:int}`, `int $id`) | path parameters (`/orders/{id}`, integer) |
| `#[Query] OrderFilters` | one query parameter per field |
| `#[Body] PlaceOrder` | the request body, as JSON and as a form (only JSON for a `#[From(Source::Json)]` class) |
| rules (`Required`, `Length`, `Range`, `Email`, `OneOf`, `Pattern`...), enums, defaults, `?T` | schema keywords (`minLength`, `minimum`, `format`, `enum`, `default`, `null`) |
| `#[Returns(201, OrderView::class)]` | the response and its schema, from `OrderView`'s public properties |
| `RequireToken`, `RequireJwt`, `RequireLogin` on the route or group | a security requirement and a `401` |
| `#[Body]`/`#[Query]`, path parameters | a `422` with the validation error shape, a `404` |
| the first paragraph of the handler's doc comment, the rest | `summary`, `description` |
| the controller (`OrderController`) | the tag (`Order`) |
| the route name, or `order.store` | `operationId` |
| `#[\Deprecated]` on the handler | `deprecated: true` |

A handler without `#[Returns]` is listed with the response "Not documented yet". A response class is described as `json_encode` writes it: every public property, `DateTimeInterface` as a date-time string, `BcMath\Number` as a decimal string, nested classes as their own schemas, and `#[ListOf(Line::class)] public array $lines` as a list. Property types `self` and `parent` refer to the property's declaring class and its parent, including inherited properties; nullable references such as `?self` also allow `null`. Trunk does not check that the handler really returns that shape.

## Seeing it

In local development, `/docs` shows Swagger UI and `/openapi.json` serves the document, both generated from the routes on each request, so they change as you edit. Swagger UI is loaded from a pinned version on jsDelivr and checked by Subresource Integrity, under its own Content-Security-Policy; "Try it out" calls only your site. Credentials typed into "Authorize" stay in the page.

Both routes answer `404` unless `openapi.serve` is on, and by default it is on only when `APP_ENV=local`. The document lists every route, so turn it on elsewhere only behind your own access control.

```bash
trunk openapi                     # writes openapi.json (or: trunk openapi docs/api.json)
```

Give that file to a client generator, Postman, Insomnia or a docs site. `trunk build` checks that the document can be generated (a `#[Returns]` class that does not exist fails the build) and writes `build/openapi.json`, which production serves when `serve` is on.

## Settings (`config/openapi.php`)

`title` (`APP_NAME`), `version`, `description`, `servers` (base URLs such as `https://api.example.com`), `serve`, and `security` for your own authentication middleware:

```php
'security' => [
    App\Http\RequireApiKey::class => ['apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key']],
],
```
