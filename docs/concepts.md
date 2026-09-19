# Concepts

## Modules and capabilities

A **module** is a plain class with `register(ContainerBuilder)` and `boot(ContainerInterface)`. `register` declares services and must not resolve any; `boot` runs after every module registered. Modules are listed, in load order, in `trunk.php`:

```php
return ['name' => 'blog', 'type' => 'web', 'modules' => [
    \Trunk\Http\HttpModule::class,
    \Trunk\Foundation\Logging\LoggingModule::class,
    \App\AppModule::class,          // yours: last
]];
```

A **capability** is metadata: a named set of modules plus the config files, `.env` settings, directories and Composer requirements it needs. `trunk package:list` shows what exists; `trunk package:install <id>` enables one (requirements first), `package:remove <id>` disables it (refused while another capability needs it).

| id | What it gives you | Requires |
| --- | --- | --- |
| `http` | Kernel, PSR-7/15 messages, router, middleware | |
| `logging` | PSR-3 structured logger, request/trace ids, secret redaction | |
| `diagnostics` | Error pipeline, request/job lifecycle reset, health checker | logging |
| `tusk` | Template engine (`*.tusk.php`) | |
| `mvc` | `Responder`: views, JSON, safe redirects | http, tusk |
| `console` | Application commands | |
| `cache` | PSR-16 cache (array, file, null) | |
| `database` | Connections, query builder, schema, migrations | |
| `orm` | Entities, maps, repositories, unit of work | database |
| `queue` | Jobs and workers | database, diagnostics |
| `observability` | In-process metrics, log-based tracing | logging |
| `auth` | Passwords, sessions, CSRF, tokens, throttling, policies | http, database, diagnostics |
| `health` | `/health/live`, `/health/ready`, `/metrics` | http, diagnostics |

A module can declare what it needs by implementing `ModuleDependencies`. A missing or misordered dependency is an **error that names the line to add or move**, never a silent reorder (checked when the app starts, in `trunk build`, and by `trunk doctor`).

## The container

Constructor injection, resolved from types, with three lifetimes: **singleton** (one per process), **scoped** (one per request or job; the default for controllers and middleware) and **transient**.

```php
public function register(ContainerBuilder $builder): void
{
    $builder->bind(PaymentGateway::class, StripeGateway::class);   // interface -> implementation
    $builder->scoped(Cart::class);                                  // one per request
    $builder->singleton(Clock::class, SystemClock::class);
    $builder->bindDefault(Metrics::class, NullMetrics::class);      // only if no module binds it: how a package replaces a default
    $builder->tag('auth.policy', PostPolicy::class);                // collected by whoever asks for the tag
}
```

The compiler checks the whole graph before deployment: unresolvable parameters, cycles, and **captive dependencies** (a singleton that depends on a scoped service fails the build with the path). Other facilities: `factory`, `service` (explicit arguments with `Reference`, `TaggedReference`, `ConfigValue`), `alias`, `closure`, `instance`. There is no service locator and no static access.

Common sources of surprise: to inject a scoped service into your own service, register the consumer with `$builder->scoped(...)`; `service(Class::class, Class::class, [])` with an empty argument list does **not** autowire.

## Configuration and environments

* `.env` holds settings; real environment variables override it. Config files read them: `$runtime->variable('NAME', 'default')`.
* **Secrets use `$runtime->secret('DB_PASSWORD')`**. `variable()` values are compiled into `build/`; `secret()` compiles a marker and reads the real environment at run time, so `build/` never contains the value.
* `APP_ENV=local` runs the development container (nothing to build). Unset or `production` means production, which **requires `trunk build` and fails closed without it**. `APP_DEBUG=1` shows detailed error pages, only in local.

## The build

`trunk build` writes `build/` with `container.php`, `routes.php`, `pipeline.php`, `config.php`, `views/`, `orm.php`, `queue.php` and `modules.php`. Every problem is collected and reported together (bad config, duplicate routes, invalid maps and jobs, module order, bad security-header values...) before anything is written. Files are written `0640` in `0750` directories. Rebuild after changing config or `.env` values your config files read.

The compiled container is not measurably faster than the development container at small scale (about 12.8 µs per warm request either way on the reference laptop); what compilation buys is that every mistake above is found before deployment, and that production never reflects, scans directories or compiles templates.

## A request

`public/index.php` builds the request from PHP's superglobals (the only place that happens), and the kernel does this for each one, inside a fresh container scope:

1. Reads the request id and trace id (`X-Request-Id`, `traceparent`) only if they match a strict pattern; otherwise generates them.
2. Runs **global middleware**, then the **error handler**, then the **router**, then **route and group middleware**, then the controller. Errors thrown anywhere inside are rendered by the error handler: JSON for API clients, an HTML page for browsers, text otherwise. Only exceptions that implement `PublicError` (such as `HttpException`) are described to clients; everything else is a generic 500 with the request id.
3. Applies the security headers (if enabled), adds `X-Request-Id`, resets per-request state (`LifecycleAware` services) and emits the response. A response to `HEAD` has no body.

The same building blocks run jobs: one scope per job, the same reset, and the job's log lines carry `originRequestId` of the request that queued it.

## Conventions worth knowing

* **No base classes.** You implement interfaces (`Module`, `Command`, `Job`, `EntityMap`, `Policy`...). The only abstract classes in the framework are internal.
* **Public API.** Only types tagged `@api` are supported; see [API.md](API.md). Everything else may change in any release.
* **Errors** carry stable codes (`NOT_FOUND`, `VALIDATION_FAILED`, `CSRF_TOKEN_INVALID`, `TOO_MANY_REQUESTS`...). You can add your own by implementing `HasErrorCode` / `PublicError`.
