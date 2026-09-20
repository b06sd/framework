# Trunk

**Trunk is a fast, secure, modular PHP framework designed for modern MVC applications.**

- **Modular.** Functionality ships as independent packages (`trunkphp/router`, `trunkphp/http`, `trunkphp/cache`, …). Third-party packages keep their own namespaces (`Acme\Payment\`) and are not required to live under `Trunk\`.
- **Lightweight core.** The core stays small; you pull in only what you need.
- **Fast by design.** Minimal bootstrap work, no runtime reflection or filesystem scanning where avoidable, OPcache-friendly, and built to support compiled config, routes and container in production.
- **Secure by default.** Security is a framework-level concern, enforced in CI (`composer audit`, PHPStan at max level, security tests). Performance never trades against security.
- **MVC.** MVC remains the application development model.
- **Explicit dependencies.** No facades, no service locator, no global framework state.

> Status: a working framework: core, compiler (lifetimes, automatic wiring), HTTP, router, Tusk, MVC, cache, the capability/package system and the `trunk` CLI. TrunkDB (database layer and ORM), the queue, auth and request validation are built.

## Install

```bash
composer global require trunkphp/framework
export PATH="$(composer global config bin-dir --absolute):$PATH"
trunk new blog --type=web && cd blog && composer install && php vendor/bin/trunk serve
```

Requires PHP 8.4+ and Composer 2. Package page: <https://packagist.org/packages/trunkphp/framework>.

## Documentation

Start with [docs/README.md](docs/README.md): [getting started](docs/getting-started.md) (a real app in about ten minutes), [concepts](docs/concepts.md), one guide per package (including [validation](docs/validation.md)), the [command](docs/cli.md) and [configuration](docs/configuration.md) references, [testing and trying to break it](docs/testing.md), [deployment](docs/deployment.md) and [troubleshooting](docs/troubleshooting.md).

## Project layout

```text
src/                      core, namespace Trunk\
  Application/            Application lifecycle (+ Exception/)
  Compiler/               container compiler and build artifacts (+ Exception/)
  Container/              PSR-11 container, builder, autowirer (+ Definition/, Exception/)
  Contracts/              interfaces packages implement (Module, Kernel)
  Foundation/             Environment, Runtime, Configuration (+ Manifest/, Exception/)
  Support/                small shared helpers (ClassName, FileWriter)
packages/<name>/          split packages, each with its own composer.json and src/
  http/src/               Message/ Stream/ Uri/ Upload/ Factory/ Server/ Emitter/ Pipeline/ Middleware/
                          Routing/ Kernel/ Build/ Exception/
  router/src/             Definition/ Pattern/ Compiler/ Matcher/ Url/ Exception/
  tusk/src/               Syntax/ Compiler/ Runtime/ Loader/ Exception/   (templates: *.tusk.php)
  mvc/src/                Responder, MvcModule
  cache/src/              PSR-16 Cache, Store/ (array, file, null), Clock/
  database/src/           Connection/ Driver/ Query/ Schema/ Migration/ Console/ Exception/
  orm/src/                Mapping/ Compiler/ Repository/ UnitOfWork/ Relation/ Diagnostics/ Console/
  queue/src/              Job/ Compiler/ Driver/ Worker/ Console/ Exception/
  validation/src/         Rules/ Attribute/ Plan/ Compiler/ Http/ Console/ (attribute rules on request classes)
  console/src/            Input/ Output/ Command/ Commands/ Scaffold/ Process/ (the trunk binary is bin/trunk)
tests/                    mirrors the source tree
  Unit/  Integration/  Security/  Performance/
  Fixtures/               test-only classes (Modules/, Services/, Controllers/, Middleware/) and views/
  Support/                test helpers (fakes, scanners, loaders)
```

Rule of thumb: one concept per folder, exceptions in `Exception/`, and tests live at the same relative path as the code they cover.

## The `trunk` CLI

```bash
trunk new customer-api --type=api      # api | web | self-contained | cli | worker
cd customer-api && composer install
trunk serve                            # PHP dev server on http://127.0.0.1:8006 (APP_ENV=local)
trunk make:controller CustomerController
trunk route:list
trunk doctor
trunk package:list                     # capabilities: what exists, what this app uses
trunk package:install cache            # enable a capability (config + .env + trunk.php)
trunk build                            # compile for production into build/
```

Application settings live in **`.env`** (created by `trunk new`, git-ignored; `.env.example` is the committed
template): `APP_ENV`, `APP_DEBUG`, `APP_PORT` (default 8006), secrets and service addresses. Real environment
variables override the file, and config files read settings with `$runtime->variable('NAME', 'default')`.

**One runtime, composable capabilities.** A project type is only a starting configuration:
`trunk.php` lists the modules the application uses, and `trunk build` compiles exactly those.
An API project compiles no view engine; a CLI project compiles no HTTP artifacts. Production
(`APP_ENV` unset or `production`) runs the compiled build and refuses to start without one;
`APP_ENV=local` runs the development container. Your own commands implement
`Trunk\Contracts\Console\Command` (a core contract, so packages can ship commands without depending on `trunkphp/console`), are listed by your module (`CommandProvider`), and get their
dependencies injected like any other service.

## Capabilities and packages

A **capability** is a named set of modules plus the config, `.env` settings and directories it needs
(`http`, `logging`, `tusk`, `mvc`, `console`, `cache`...). `trunk package:install <id>` enables one
(requirements first, existing files never overwritten); `trunk package:remove <id>` disables it (and
refuses while another capability needs it). A Composer package can provide a capability by declaring
`extra.trunk.capability` in its `composer.json`; `trunk package:install vendor/name` runs
`composer require` and then enables it. `trunk doctor` reports missing requirements, and
`trunk build` refuses to build with one and lists what it compiled and what it left out.

### What a capability installs

The core is small on purpose, so a capability can need Composer packages the core does not: `http` needs the PSR HTTP interfaces (`psr/http-message`, `psr/http-factory`, `psr/http-server-handler`, `psr/http-server-middleware`), `cache` needs `psr/simple-cache`, `database` needs `ext-pdo`. `trunk new` writes exactly what its profile needs into the project's `composer.json`; `trunk package:install <id>` runs one `composer require` for whatever is missing *before* it touches `trunk.php` (a failed download changes nothing); `trunk doctor` and `trunk build` refuse to pass when an enabled capability's packages or extensions are missing and print the `composer require` line to run. Only built-in capabilities declare these; a third-party package's metadata can never add Composer requirements. `composer test:install` scaffolds every project type and does a real `composer install`, `doctor`, `build` and request.

### Secrets, module order and trust

- **Secrets**: `$runtime->variable('X')` is *compiled into* `build/`; a password or token must use `$runtime->secret('X')`, which compiles a marker and reads the real environment at run time, so `build/` never contains the value. Build files are written 0640 in 0750 directories, and `trunk doctor` warns about anything looser.
- **Module order**: a module may implement `Trunk\Contracts\ModuleDependencies` (`requires()`, `after()`). `trunk.php` stays the single ordered list; a missing or misordered dependency is an error that names the line to add or move (dev, `trunk build` and `trunk doctor`), never a silent reorder. `trunk package:install` inserts modules in a valid position.
- **Module trust**: a module runs with the full privileges of your application, exactly like any Composer dependency. Built-in capabilities ship in this repository; `trunk doctor` lists every third-party capability with its package so you can review what you enabled.
- **Replacing a default**: core binds no-op defaults (`Metrics`, `Tracer`, `MetricsExporter`) with `ContainerBuilder::bindDefault()`, which applies only if no module binds the same id, so a package replaces one by binding it, whatever the module order.

### HTTP limits and route middleware

`config/http.php` bounds untrusted input: `max_body_bytes` (413 by header and while streaming), `max_files`, `max_file_bytes`, `max_json_depth`; malformed or conflicting `Content-Length`/`Transfer-Encoding` is a 400 and a JSON body with the wrong content type is a 415. Forwarded headers are never trusted by default. List the reverse proxies you run in `http.trusted_proxies` (IPs or CIDRs, validated at build time) and only requests whose TCP peer is one of them may set scheme, host and port through `X-Forwarded-Proto/Host/Port` (the value the nearest proxy appended is used) and the client address through `X-Forwarded-For` (read right to left, skipping trusted proxies); the result is the `client_ip` request attribute. RFC 7239 `Forwarded` is never read. Do not list `0.0.0.0/0`. Middleware can be attached per route or group: `$routes->group('/admin', fn ($r) => ..., middleware: [AdminOnly::class])` and `$routes->get('/x', [C::class, 'a'], middleware: [Audit::class])`; ids are validated and compiled, and `trunk route:list` shows them. Order: global, error, routing, group/route middleware, controller.

## Database (TrunkDB)

`trunk package:install database` enables the layer (SQLite by default; MySQL and PostgreSQL are configured in `config/database.php` and `.env`). Inject `Connection`; there is no global `DB::`.

```php
$customers = $connection->table('customers')->where('active', true)->orderBy('name')->get();
$connection->transaction(fn (Connection $c) => $c->table('customers')->insert(['name' => $name]));
$connection->select('SELECT * FROM customers WHERE id = :id', ['id' => $id]);
```

Values are always bound (native prepared statements); identifiers are validated and quoted; `Raw` is the only unsafe escape hatch. With the console enabled: `trunk make:migration create_customers_table`, `migrate`, `migrate:status`, `migrate:rollback [--step=N]`, `migrate:fresh` (refused in production). The ORM is a separate package (`trunkphp/orm`) built on this layer. MySQL/PostgreSQL live tests run when `TRUNK_TEST_MYSQL_*` / `TRUNK_TEST_PGSQL_*` (`HOST`, `DATABASE`, `USER`, `PASSWORD`, `PORT`) are set.

## ORM (TrunkORM)

`trunk package:install orm` (it needs `database`). Entities are plain PHP; you describe them in explicit map classes. `trunk make:entity Customer` creates both: the entity in `app/Entities/`, its map in `app/Orm/` (maps are discovered as `app/Orm/*Map.php`). There is no `Model` base class; see ["Coming from Laravel?"](docs/orm.md#coming-from-laravel-or-another-framework). `trunk build` validates every map and generates hydrators into `build/orm.php`; production runs only generated code, with no reflection.

```php
public function __construct(private EntityManager $orm) {}

$customers = $this->orm->repository(Customer::class);
$customer  = $customers->query()->where('email', $email)->with('orders')->first();   // 2 queries, never lazy
$customer->name = 'Ada';                                   // dirty checking
$this->orm->persist(new Customer(name: 'Grace'));
$this->orm->flush();                                       // one transaction, only changed columns
$this->orm->relatedMany($customer, 'orders');              // throws RelationNotLoaded instead of querying
```

Security defaults: values are always bound; untrusted request input goes through `filter()`/`sortBy()`/`Repository::input()`, which only reach properties the map marks `filterable()`/`sortable()`/allowlisted (no mass assignment); `hidden()` columns are never selected (unless `withHidden()`), never serialise and never dump; global scopes and soft deletes are ANDed around every query, related rows included; optimistic locking via `version()`; corrupt database values raise errors that never contain the value. Speed defaults: generated hydrators, identity map with snapshot dirty checking (unchanged entities cost no SQL), one query per eager-loaded relation, `readOnly()` and keyset `cursor()` for big reads, and `EntityManager::nPlusOneFindings()` in development.

## Errors, logging and memory

**Principle:** the normal path stays cheap; validation and resolution move to build time; diagnostics, safety and error handling are never removed for speed.

- **One error pipeline** (the `diagnostics` capability, which builds on `logging`) for HTTP, CLI and workers: classify, log (PSR-3), report (`trunk.error_reporter` hooks), render. Only exceptions implementing `PublicError` (`HttpException`, `ValidationException`, your own) are described to clients; every other exception is `INTERNAL_ERROR` with a request id, never a path, SQL, credential or trace. Machine-readable codes: `ErrorCode` or your own (`CUSTOMER_NOT_FOUND`). Interfaces, not base classes: `HasErrorCode`, `PublicError`, `DeveloperHint`.
- **Presentation follows the client**: JSON `{"error":{"code","message","requestId"}}` for API clients, your Tusk page (`resources/views/errors/404.tusk.php`, `errors/error.tusk.php`) for browsers, plain text otherwise (`errors.format` forces one). Development shows the exception, the fix, the source lines and the request (secrets removed); production never does.
- **Request ids and trace ids** (`X-Request-Id`, W3C `traceparent`) are accepted only if they match a strict pattern, otherwise generated (`req_` + ULID). Every response carries `X-Request-Id`; every log record written meanwhile carries it too.
- **Logging** is plain PSR-3 (`LoggerInterface`), structured JSON lines with service, environment and request/trace ids, to stderr (production default), a daily file (local default) or nowhere (`config/logging.php`). Secrets are redacted automatically (`password`, `token`, `authorization`, `cookie`, `api_key`, ... at any depth, plus `Bearer ...` and `password=...` inside text); control characters, line breaks and bidi overrides are escaped, so log lines cannot be forged. Exception traces never include arguments.
- **Long-running processes**: services holding per-process state implement `LifecycleAware::reset()` (tag `trunk.lifecycle`); the HTTP kernel and the queue worker reset them after every request or job, and an open transaction is rolled back and reported. `MemoryMonitor` runs `gc_collect_cycles()` only every `gc_interval` units (never per request), flags steady memory growth once, and asks the worker to stop cleanly at its memory limit (`queue.worker.*`).
- **PHP-level failures**: warnings and notices (thrown in development, logged in production), uncaught exceptions, and fatal errors including memory exhaustion leave an emergency log line and a generic 500/message. Memory exhaustion cannot be recovered from; the aim is to leave enough evidence to diagnose it.

**Observability seams** (standards-based, all optional): `trunk package:install health` adds `/health/live`, `/health/ready` (checks tagged `trunk.health_check`, up/down only in production) and a token-protected `/metrics` (Prometheus text; needs `METRICS_TOKEN` and the `observability` capability, `trunk package:install observability`, which keeps in-process metrics). `observability.tracing` writes one structured span record per request and job (W3C `traceparent` in, `Span::traceparent()` out; the `Tracer`/`Span`/`Metrics` interfaces map onto OpenTelemetry, but no OpenTelemetry SDK is bundled). A job carries the request id and trace id of whoever queued it, so a worker log line names the originating request.

## Authentication and authorization (TrunkAuth)

`trunk package:install auth` (needs `console` for the commands), then `trunk auth:table --users && trunk migrate`.
Nothing runs on a route until you put its middleware there, so API routes and public pages pay nothing.

```php
// web pages: sessions, CSRF, login required
$routes->group('/account', fn ($r) => ..., middleware: [SessionMiddleware::class, CsrfMiddleware::class, RequireLogin::class]);
// APIs: bearer tokens, no cookies, no CSRF
$routes->group('/api', fn ($r) => ..., middleware: [RequireToken::class]);
```

Inject `Auth` (`attempt($email, $password)`, `login()`, `logout()`, `user()`, `intended()`), `Csrf` (`token()` for forms),
`Session`, `Gate` (`allows()`, `authorize()`) and `TokenManager` (`issue()`, `revoke()`); all but the last are request-scoped.

- **Passwords**: argon2id via `password_hash` with the parameters in `config/auth.php`, upgraded automatically on login; a login for an unknown user does the same amount of hashing as a real one, and every failure gives the same answer.
- **Sessions**: server-side (database, file or array store); the cookie carries a random 256-bit id and only its SHA-256 is stored; the id changes at login and logout; idle and absolute timeouts; a user's `session_version` changes sign them out everywhere; visitors who store nothing get no cookie and no row.
- **Cookies**: `HttpOnly`, `SameSite=Lax`, `Secure` and `__Host-` prefixed in production, built by a validated `Cookie` class that cannot be tricked into header injection.
- **CSRF**: a per-session secret, masked freshly in every token, checked with `hash_equals` plus `Origin` and `Sec-Fetch-Site`.
- **Tokens**: `trk_<id>.<secret>`; only the secret's hash is stored; abilities and expiry; `trunk auth:token <user-id> <name>` prints one once.
- **Throttling**: failed logins are counted per account+address and per address in the database (atomic updates, `Retry-After`, uses the trusted-proxy `client_ip`).
- **Authorization**: `Gate` denies by default. Tag a `Policy` (for objects) or an `Ability` (general permissions) service with `auth.policy` / `auth.ability`; the build rejects tags on classes of the wrong kind. A bearer token can only do what it lists.
- **Users** come from the `UserProvider` interface; the default reads a configurable table through `trunkphp/database`, and an ORM app can bind its own.

Tokens are tied to the owner's `session_version`, so changing it (a password change) ends sessions **and** tokens together, and one address can start only `auth.throttle.max_new_sessions_per_ip` anonymous sessions per window. Measured on this machine (PHP 8.5, SQLite file): an authenticated session request costs about 41 µs against 17 µs for an unauthenticated route (one session read and one user read, no writes while the session is in use); a bearer-token request about 38 µs; a login is dominated by argon2id (about 150 ms at the default 64 MiB / 4 passes; 19 MiB / 2 passes, the OWASP minimum, is 19 ms). Lower `auth.password.memory_cost` and `time_cost` if login concurrency matters more than margin.

Not built yet: remember-me cookies, password reset and email verification (they need mail), two-factor, OAuth/OIDC, WebAuthn, other session stores. `trunk auth:prune` (run it from cron) removes dead sessions, tokens and throttle counters.

## Queue and jobs (TrunkQueue)

`trunk package:install queue`, then `trunk queue:table && trunk migrate` for the database driver. A job is a plain class: the constructor is the payload, `handle()` receives its services from the container.

```php
final readonly class SendWelcomeEmail implements Job
{
    public function __construct(public int $userId) {}                 // ints, floats, strings, bools, arrays, backed enums, DateTimeImmutable
    public function handle(Mailer $mailer, Users $users): void {}      // injected, fresh container scope per job
    public static function options(): JobOptions { return new JobOptions(tries: 5, backoff: [10, 60, 300], timeout: 30, queue: 'mail'); }
}

$queue->dispatch(new SendWelcomeEmail(7));                              // inject Queue; no facade
$queue->dispatch(new SendWelcomeEmail(7), delay: 60, queue: 'mail');
$queue->dispatchMany($jobs);                                            // one multi-row insert
```

`trunk queue:work [--queue=a,b] [--once] [--stop-when-empty] [--max-jobs=N] [--max-time=S] [--memory=MB]` runs jobs; `queue:failed`, `queue:retry {id|all}`, `queue:flush`, `make:job Name` manage them. Jobs are found in `app/Jobs`; `trunk build` validates them (unsafe constructors, container-injecting `handle()`, timeouts that do not fit the visibility window) and generates codecs into `build/queue.php`.

Safety: payloads are JSON only (no `unserialize`), the stored job name is just a key into the compiled allowlist (an unknown name never touches the autoloader), decoding is strict and errors never echo values, failure messages are stored only in development, an attempt is counted when a job is claimed (a job that kills its worker still runs out of tries), claiming is an atomic conditional `UPDATE` (no `SKIP LOCKED`; verified with 4 concurrent worker processes on SQLite, MySQL and PostgreSQL), and a job dispatched inside a database transaction commits or rolls back with your data. Timeouts and graceful shutdown need `ext-pcntl`. Long-running workers share singletons across jobs, so recycle them (`--max-jobs`, `--max-time`, `--memory`) under a process supervisor.

## Validation (TrunkValidation)

`trunk package:install validation`, then `trunk make:request Signup`. A request is a class whose constructor parameters are the fields; attributes are the rules. Valid input becomes an object, invalid input a `422` that names each wrong field and never repeats what was sent.

```php
final readonly class Signup
{
    public function __construct(
        #[Required, Email] public string $email,
        #[Required, Length(min: 12), Sensitive] public string $password,
        #[Range(13, 120)] public ?int $age = null,
    ) {}
}

$signup = $this->requests->validate(Signup::class, $request);   // inject RequestValidator; a Signup or a 422
```

JSON stays strictly typed, form and query values are read strictly from text, backed enums, nested objects and lists of objects work, and a wrong type is a field error, never a server error. `trunk build` checks every request class and writes `build/validation.php`. Full guide: [docs/validation.md](docs/validation.md).

## Dependency injection in one minute

Write normal constructors; Trunk wires them. Register only what it cannot infer:

```php
public function register(ContainerBuilder $builder): void
{
    $builder->bind(PaymentGateway::class, StripeGateway::class);   // interface -> implementation
    $builder->scoped(RequestContext::class);                       // one per request
    $builder->transient(Token::class);                             // new every time
    $builder->autowire(Mailer::class, with: ['from' => new ConfigValue('mail.from', 'string')]);
    $builder->tag('listeners', AListener::class, BListener::class); // inject with new TaggedReference('listeners')
}
```

Controllers and middleware are resolved per request (scoped) and everything they need is wired
automatically. A singleton may not depend on a scoped service; the build fails and says why.

## Public API and versioning

Only types tagged `@api` are public; everything else is internal even when PHP lets you call it. The list, the
compatibility rules, the extension points (container tags, capability metadata) and the versioning policy are in
[docs/API.md](docs/API.md); changes to public API are recorded in [UPGRADE.md](UPGRADE.md).

## Testing

`composer test` (unit, integration, security, architecture, performance), `composer test:install` (scaffolds every project type and runs a real `composer install`), and `composer test:browser` (PHP's built-in server plus Google Chrome driven by `playwright-core`: sign-in, CSRF, cross-site forms, framing, session fixation, timeouts, lockout, tokens and security headers; it skips with a message when node or Chrome is missing). Findings and measurements from the http, mvc and orm hardening pass are in [docs/HARDENING_REPORT.md](docs/HARDENING_REPORT.md).

## Requirements

PHP 8.4+ and Composer 2.x.

## Development

```bash
composer install
composer quality      # format check + static analysis + tests
composer test
composer analyse
composer format       # apply code style
composer audit
```

## License

MIT
