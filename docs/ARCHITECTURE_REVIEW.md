# Trunk: architecture and foundation review

Source of truth: the repository at the time of writing (PHP 8.5.5 locally, `^8.4` required). Every number below was measured on one developer laptop (macOS, PHP CLI, SQLite in memory, no OPcache preload) and is a *relative* indicator, not a benchmark to publish. Anything I did not verify is marked **needs verification**.

Repository size: core `src/` 109 files / ~6.1k lines; packages: cache 13, console 31, database 48, http 42, mvc 3, orm 41, queue 51, router 19, tusk 43 files (~19.5k lines in packages). Tests: 116 test classes, ~1,200 test methods (Unit 72, Integration 32, Security 12, Performance 3 files), PHPStan level max with no baseline and no suppressions, PHP-CS-Fixer clean, `composer audit` clean.

---

## 0. Status: every finding resolved in Section 17

Each fix is pinned by tests; the architecture rules behind them run in the `Architecture` suite.

| Finding | Status | Where / how it is enforced |
| --- | --- | --- |
| F1 Console contracts in an optional package | **Resolved** | `Trunk\Contracts\Console\*` in core; cache/database/orm/queue implement the core contract. `ArchitectureRulesTest` (layering, manifest honesty). |
| F2 Core distribution pulled optional requirements | **Resolved after a second pass** (the first fix broke a real install; see ARCHITECTURE_REVIEW_2.md, R1, fixed and covered by `tests/Install`) | Root `require` is `php`, `psr/container`, `psr/log`. `ScopedIds` are contributed by `HttpModule`. |
| F3 `console` required `trunkphp/http` | **Resolved** | Manifest honesty test: every required `trunkphp/*` package is imported. |
| F4 Secrets in `build/`, artifact permissions | **Resolved** | `secret()` / `EnvSecret` resolved from the real environment at run time; build files 0640, directories 0750; `doctor` warns. `SecretsEndToEndTest`, `EnvSecretTest`. |
| F5 No module dependency metadata | **Resolved** | `ModuleDependencies` + `ModuleGraph` (dev, build, doctor); errors name the fix. `ModuleGraphTest`. |
| F6 No request input limits | **Resolved** | `RequestLimits`, `BoundedStream`, `JsonBody`, upload bounds (413/415/400). `RequestLimitsTest`, upload fuzz test. |
| F7 No route/group middleware | **Resolved** | Route and group `middleware:` compiled into the route table. `RouteMiddlewareTest`. |
| F8 Tusk runtime errors lacked the source line | **Resolved** | Message names `template:line`; the development error page shows the template source around that line (`SourceLocated` in core, `SourceLocator` for the source loader; a compiled build has no sources, so nothing is shown). `SourceMappingTest`. |
| F9 Concrete observability in core | **Resolved** | `ContainerBuilder::bindDefault()`, core `MetricsExporter` and `Null*` defaults, new `packages/observability` (`Trunk\Telemetry`, capability `observability`, config `observability.php`). `BindDefaultTest`. |
| F10 Duplicated artifact loading | **Resolved** | `Trunk\Compiler\ArtifactLoader` used by ORM, queue, route table and Tusk loaders. `ArtifactLoaderTest`. |
| F11 `ContextHolder` process-local | **Resolved** for `Fiber`-based concurrency | Per-fiber context, `reset()` clears all. `ContextHolderTest`. Other concurrency models must use a scope per unit of work (documented). |
| Module trust | **Addressed** | `doctor` lists third-party capabilities with a trust warning; `ModuleGraph` reports a missing class instead of a fatal. README "Module trust". |
| Forwarded headers | **Resolved** | `http.trusted_proxies` (default empty, CIDRs validated at build) with `TrustedProxies`; `X-Forwarded-Proto/Host/Port/For` honoured only from a listed peer, read from the right, malformed values ignored; RFC 7239 `Forwarded` never read. `TrustedProxiesTest`, `UntrustedInputTest`. |
| Log directory modes | **Verified** | Log directory is created 0750; `doctor` warns when storage/logs is world-writable. |
| Upload handling | **Fuzzed** | 400 hostile `$_FILES` structures fail cleanly. |

Measurements below were re-run after Section 17.


## 1. Executive assessment

| Area | Assessment | Evidence |
| --- | --- | --- |
| Dependency direction (core never imports optional packages) | **Strong** | Import scan: `src/` has zero `use Trunk\{Http,Router,Tusk,Mvc,Console,Cache,Database,Orm,Queue}` statements. Package to package edges are one-way (see §4). |
| Dependency injection | **Strong** | Constructor auto-wiring, `bind`, `service`, `factory`, `ConfigValue`, tags, three lifetimes, captive-dependency check, cycle detection at build time *and* runtime, PSR-11, request scopes. Ambiguous interface resolution is by explicit binding (one binding per id). |
| Compiler | **Healthy, value is validation more than speed** | Compiles container, routes, module list, config, Tusk templates, ORM metadata/hydrators, queue codecs. Measured runtime gain in the request path is within noise (§6). Build-time error aggregation is the real payoff. |
| Error handling and logging | **Strong** (new) | One pipeline for HTTP/CLI/worker; only `PublicError` reaches clients; request/trace ids; redaction and sanitising fuzz-tested. |
| Module system | **Needs refinement** | Modules are plain `register`/`boot` objects ordered by `trunk.php`; modules cannot declare dependencies or ordering. Capability `requires` covers only capability metadata. |
| Core boundary in *packaging* | **Needs refinement** | The root `composer.json` (the core distribution) requires `ext-pdo`, PSR HTTP and PSR SimpleCache; optional packages contain console-integration classes that import `Trunk\Console` without requiring it (§5, F1/F2). |
| HTTP hardening | **Needs refinement / not yet validated** | Host header and request target are strictly validated; there is **no body-size or upload limit in the framework** (relies on PHP/web-server config). |
| Router/MVC | **Healthy** | PSR-7/15; compiled route table; duplicate-route detection at build; MVC is an optional `Responder` and not required for API/CLI/worker. Groups are prefix-only (no group middleware). |
| Tusk | **Healthy, gaps** | Escaped by default, explicit `raw`, layouts/slots, compiled. Syntax errors carry template name and line; *runtime* template errors do not map back to the source line (needs verification, F8). |
| Database / ORM | **Healthy** | Database usable without the ORM; ORM depends only on database; verified on SQLite, MySQL, PostgreSQL. |
| Performance claims | **Not yet validated** against other frameworks | No Laravel/Symfony/Slim baselines were run (§6). |
| Ecosystem readiness (third-party packages) | **Not yet validated** | Capability metadata mechanism exists and is tested (untrusted metadata is validated), but no third-party package has exercised it. |

## 2. Current architecture (as discovered)

```text
                                   Trunk
                                     │
        ┌────────────────────────────┴───────────────────────────────┐
        │  CORE  (src/, package trunkphp/framework)                        │
        │  Application lifecycle · Container (DI, lifetimes, scopes)    │
        │  Compiler/BuildRunner · Module + Capability system            │
        │  Foundation (Runtime, Configuration, .env, Project)           │
        │  Error · Logging (PSR-3) · Lifecycle/Memory · Health          │
        │  Observability (Metrics, Tracer) · Contracts · Support        │
        └────────────────────────────┬───────────────────────────────┘
                                     │  (packages depend on core; never the reverse)
   ┌─────────┬───────────┬───────────┼───────────┬───────────┬──────────┐
 router     http        tusk       cache     database     console    (auth: empty dir)
   ▲         │ ▲          ▲                       ▲  ▲         ▲
   └─────────┘ └── mvc ───┘                       │  │         │ (integration modules only)
                 (http + tusk)                    orm queue ────┘
```

Traced flows (all exist and are tested end to end):

* **Startup, web**: `public/index.php` → `WebEntry::run` → `ErrorHandlers::register` → `ProjectLoader` (trunk.php) → `EnvironmentFile` (.env, real env wins) → `ApplicationFactory::runtime/create` → `Application::register/boot` (dev: `ContainerBuilder` from modules; production: generated `CompiledContainer`, refuses to run without `build/`) → `HttpKernelFactory` (dev collects routes/middleware from modules; production loads `routes.php`/`pipeline.php`) → `HttpKernel::run`.
* **Request**: `ServerRequestCreator` (strict Host/target/protocol) → `RequestContext` (ids) → scope → `Pipeline` (global middleware, `ErrorHandlingMiddleware`, `RoutingMiddleware`) → `ControllerDispatcher` (argument plan compiled at build) → response → span/metrics → lifecycle cleanup → `X-Request-Id`.
* **Error**: exception → `ExceptionHandler` (classify, log, report) → negotiated renderer (JSON / Tusk page / text / dev page).
* **Build**: `trunk build` → every `BuildContributor` plans (all errors aggregated) → container compiled with the union of roots → written to a staging directory, then swapped in.
* **Worker**: `queue:work` → `Worker` (fresh scope per job, lifecycle reset, memory policy, timeout via pcntl).

Note: `packages/auth` is an empty directory. Mail, storage, events and scheduler do not exist. Nothing in core depends on them.

## 3. Core boundary report

| Component | Current location | Should be | Reason | Action |
| --- | --- | --- | --- | --- |
| Container / DI | `src/Container` | CORE | Everything else builds on it. | Keep. |
| Compiler / build runner | `src/Compiler` | CORE | Extension point (`BuildContributor`) used by 6 packages. | Keep. |
| Module + capability system | `src/Foundation/Capability`, `Manifest` | CORE FOUNDATION | Needed for optional packages to plug in. | Add module dependency/ordering (F5). |
| Configuration, Runtime, .env | `src/Foundation` | CORE | | Keep. |
| Error pipeline | `src/Error` | CORE FOUNDATION | Shared by HTTP/CLI/worker. | Keep. `ErrorHandlers` echoing HTTP headers is HTTP-aware; acceptable (kind-switched). |
| Logging (PSR-3, redaction) | `src/Logging`, `Foundation/Logging` | CORE FOUNDATION | Required by error pipeline. | Keep. Consider splitting the *concrete* structured logger into an optional package later; the interfaces stay. |
| Lifecycle / MemoryMonitor | `src/Lifecycle` | CORE FOUNDATION | Runtime lifecycle concern. | Keep. |
| Health, Metrics, Tracer | `src/Health`, `src/Observability` | CORE FOUNDATION (interfaces) / DEVELOPER CONVENIENCE (`InMemoryMetrics`, `PrometheusFormatter`, `LogTracer`) | Interfaces are seams; implementations are conveniences. | Keep interfaces in core; move implementations to a `trunkphp/observability` package when a second implementation appears (F9). |
| `ArtifactWriter::SCOPED_IDS` mentions PSR-7 `ServerRequestInterface` | `src/Compiler` | CORE, but HTTP-shaped | Only HTTP leak found in core. | Let packages declare scoped ids through a contribution (F2). |
| HTTP (PSR-7/15, kernel) | `packages/http` | OPTIONAL PACKAGE | Needed only for web/API. | Keep. |
| Router | `packages/router` | OPTIONAL PACKAGE (depends on nothing but PSR-7) | Good boundary. | Keep. |
| MVC (`Responder`) | `packages/mvc` | DEVELOPER CONVENIENCE | 132 lines; requires http+tusk. | Keep optional. |
| Tusk | `packages/tusk` | OPTIONAL PACKAGE | Zero dependencies. | Keep. |
| Console | `packages/console` | OPTIONAL PACKAGE, but *contracts* belong lower | Four packages implement its `Command` interface (F1). | Move `Command`, `CommandProvider`, `CommandCollector`, input/output interfaces to core contracts. |
| Cache | `packages/cache` | OPTIONAL PACKAGE | PSR-16 only. | Keep. |
| Database | `packages/database` | OPTIONAL PACKAGE | Usable without ORM. | Keep. |
| ORM | `packages/orm` | OPTIONAL PACKAGE | Depends only on database. | Keep. |
| Queue | `packages/queue` | OPTIONAL PACKAGE | Depends on database (driver) and core. | Consider making the database driver its own package once a second driver exists (F10). |
| Auth | `packages/auth` (empty) | OPTIONAL PACKAGE / APPLICATION POLICY | Not built. | Do not start until F1/F2/F5 settle. |

## 4. Dependency graph

Computed from every `use Trunk\...` import, and now **enforced** by `tests/Architecture/ArchitectureRulesTest.php` (state after Section 17):

```text
core          -> (nothing outside core)
router        -> core
http          -> router, core
tusk          -> core
mvc           -> http, tusk, core
console       -> router, core
cache         -> core
database      -> core
orm           -> database, core
queue         -> database, core
observability -> core
```

* **Cycles: none**, and a test fails if one appears.
* **Direction: correct at source and metadata level.** Core imports no package; each package's `composer.json` requires exactly the `trunkphp/*` packages it imports; the root `require` is `php`, `psr/container`, `psr/log` only.
* Resolved in Section 17: the console contracts moved to core (F1), the root requirements were trimmed (F2), `console` no longer requires `trunkphp/http` (F3).

## 5. Critical findings

**F1: Console contracts live in an optional package that four other packages implement.**
*Why it matters*: `database/orm/queue/cache` contain `Console/` classes that will not load without `trunkphp/console`; static analysis, IDEs and Composer cannot tell. The capability system prevents loading them at runtime (verified by tests), which is why this is not P0.
*Evidence*: import scan (`console` edges from cache 6, database 24, orm 11, queue 30); `suggest` blocks in their composer.json.
*Risk*: a third-party package copying the pattern gets the same hidden coupling; a project without console gets broken references in tooling.
*Change*: move `Command`, `CommandDefinition`, `CommandProvider`, `CommandCollector`, `Input`, `Output` (interfaces only) into core contracts; keep the implementations in `packages/console`.
*Priority*: **P1**.

**F2: The core distribution is not minimal.**
*Evidence*: root `composer.json` requires `ext-pdo`, `psr/http-factory`, `psr/http-message`, `psr/http-server-*`, `psr/simple-cache`; `src/Compiler/ArtifactWriter.php` names `Psr\Http\Message\ServerRequestInterface` in `SCOPED_IDS`.
*Risk*: "Trunk Core" cannot honestly claim to run without HTTP/DB today; a CLI-only app installs HTTP PSR packages.
*Change*: publish `trunkphp/framework` (core) with only `psr/container`, `psr/log`; let packages contribute scoped ids and PSR requirements. The monorepo root can stay as a dev aggregate.
*Priority*: **P1** (before the first tagged release).

**F3: `trunkphp/console` requires `trunkphp/http` without importing it.**
*Priority*: **P2** (trim the requirement after F1).

**F4: Production build embeds secrets in a world-readable file.**
*Evidence (verified)*: with `DB_PASSWORD` set at build time, `build/config.php` contains the password in plain text, mode `0644`, directory `0755`; `build/` is git-ignored by the scaffold.
*Risk*: any local user or a mis-served `build/` directory exposes credentials; `trunk build` output also appears in CI artifacts.
*Change*: write `config.php` with mode `0640` and directory `0750` by default; provide a first-class way to keep secrets out of the build (resolve named env values at runtime, e.g. `Runtime::secret('DB_PASSWORD')` marker that compiles to an env lookup). Document that `build/` is sensitive.
*Priority*: **P1**.

**F5: Modules cannot declare dependencies or ordering.**
*Evidence*: `Module` has only `register`/`boot`; order is `trunk.php` order; capability `requires` exists only in capability metadata. Cycle detection is therefore moot for modules, and a third-party module that needs another cannot say so.
*Risk*: subtle registration-order bugs (a module using a tag registered later); ecosystem friction.
*Change*: optional `ModuleMetadata` (`requires`, `after`) validated at build time with cycle detection; keep the interface unchanged.
*Priority*: **P1** before the ecosystem grows.

**F6: No request size limits in the framework.**
*Evidence*: `ServerRequestCreator` streams `php://input` and applies no maximum; no upload size/count policy; no JSON body parsing helper with depth/size limits (the queue codec has them, HTTP does not).
*Risk*: memory/CPU exhaustion if PHP `post_max_size`/web server limits are left high (**potential**, environment-dependent).
*Change*: `http.max_body_bytes` enforced from `Content-Length` and while streaming; documented defaults; a bounded `JsonBody` reader.
*Priority*: **P1**.

**F7: Route groups are prefix-only.**
*Evidence*: `RouteCollector::group(string $prefix, Closure)`; middleware is global via `MiddlewareProvider`.
*Risk*: auth/CORS/rate limits (future packages) need per-group middleware; without it they will invent private mechanisms.
*Change*: group and route middleware in the route definition, compiled into the route table.
*Priority*: **P1** before auth.

**F8: Tusk runtime errors lack source line mapping (needs verification).**
*Evidence*: `TemplateSyntaxException` carries template and line; `TemplateRuntimeException::in()` wraps the exception with the template name only.
*Change*: emit a line map with compiled templates; use it in the dev error page.
*Priority*: **P2**.

**F9: Observability implementations sit in core.**
`InMemoryMetrics`, `PrometheusFormatter`, `LogTracer` are conveniences. Keep the interfaces in core; move implementations out when a second one exists. *Priority*: **P3**.

**F10: Repeated "configured registry + generator + artifact loader" pattern** in ORM (`ConfiguredRegistry`, `OrmCodeGenerator`, `OrmArtifact`), Queue (`ConfiguredJobRegistry`, `JobCodeGenerator`, `JobArtifact`) and Tusk views. Three near-identical shapes; a small shared artifact helper would remove duplication. Do this when a fourth consumer appears, not before. *Priority*: **P3**.

**F11: `ContextHolder` is process-local mutable state.**
It is an explicit service, cleared by the lifecycle, and documented as not fiber-safe. Fine for FPM/CLI/one-at-a-time workers; would need a per-fiber variant for concurrent servers. *Priority*: **P3** (only when a resident async server is a goal).

## 6. Performance risks

**Observed** (this machine; re-measured after Section 17, medians; see `tests/Performance/BootstrapBenchmark.php`):

| Measurement | p50 | p95 | p99 |
| --- | --- | --- | --- |
| Bootstrap in-process, development container | 58 µs | 75 µs | 137 µs |
| Bootstrap in-process, compiled container | 61 µs | 70 µs | 128 µs |
| Simple request, development container | 14.5 µs | 16.8 µs | 20.5 µs |
| Simple request, compiled container | 14.4 µs | 16.5 µs | 19.8 µs |
| 404 request (exception + handler + renderer) | 42.4 µs | 48.4 µs | 67.5 µs |
| Route match static / dynamic / miss (1,000 routes) | 0.4 / 3.2 / 2.1 µs | | |
| First resolution of a 400-service tree, dev vs compiled | 46.0 vs 39.7 µs | | |
| Memory growth over 20,000 requests | 0 bytes (peak 22 MB) | | |
| Raw SQLite select / builder `first()` / ORM readOnly / ORM identity-map `find()` | 2.3 / 5.8 / 18.6 / 0.1 µs | | |
| Cold PHP process per request, local (dev container) | 60.0 ms | 70.5 ms | 74.2 ms |
| Cold PHP process per request, production (compiled) | 59.1 ms | 79.6 ms | 80.6 ms |
| Same, with OPcache file cache (local / production) | 59.1 / 57.9 ms | 64.0 / 64.4 | 74.0 / 75.2 |
| Bare `php hello.php` process (baseline on this machine) | 47.7 ms | 68.1 ms | 126 ms |

Reading the numbers honestly:

* Trunk adds roughly 10 ms on top of a bare cold PHP process here, almost all of it class loading; the warm in-process request path is ~15 µs.
* **The compiled container is not measurably faster than the development container at this scale** (15.0 vs 14.4 µs; cold 59.9 vs 58.8 ms). Compilation's demonstrated value is *validation* (unresolved dependencies, cycles, captive dependencies, duplicate routes, invalid jobs and maps, all before deployment) and the production fail-closed guard.
* The default happy-path cost of the error/logging/lifecycle/observability hooks measured 13.5–14.5 µs without vs 14.0–14.8 µs with (about +0.4 µs); enabling metrics plus span logging adds ~18 µs.

**Likely** (reasoned, not measured): a large application (hundreds of services and routes) benefits more from the compiled container and route table than the fixtures show; dev-mode reflection in `Autowirer` costs a few µs per service on first resolution.

**Needs benchmarking**: a realistic app (200+ services, 300+ routes, Tusk pages) with OPcache preload; throughput (requests per second) under php-fpm or a resident server; comparison with Laravel, Symfony and Slim. I did **not** run these frameworks (not installed here) and make no relative speed claim. Suggested method: identical hello/JSON/DB endpoints, `wrk`/`bombardier`, php-fpm with OPcache, 3 runs each, report p50/p95/p99 and RSS.

## 7. Security risks

**Confirmed**
1. Secrets are compiled into `build/config.php` with mode `0644` (F4).
2. No framework-level request body/upload limits (F6): confirmed absent; exploitability depends on deployment.

**Potential**
3. Third-party modules and `extra.trunk.capability` metadata: metadata is validated (class-name syntax, untrusted, tested), but a listed module class is instantiated by the runtime, i.e. installing a Composer package is a full code-trust decision (same as any Composer dependency). Document it; there is no isolation between modules.
4. `ContextHolder` (process-local) would mix ids if a future server ran requests concurrently in one process.
5. Emergency/log files are created `0640` (file) but the directory follows the umask; `storage/logs` permissions are the operator's responsibility (`doctor` checks writability, not mode).

**Needs verification**
6. Behaviour behind proxies: `Host`/scheme come from the server variables only (no `X-Forwarded-*` handling), which is safe but means URL generation behind TLS-terminating proxies needs explicit configuration.
7. Multipart parsing and upload handling rely on PHP's `$_FILES`; validation of upload metadata was reviewed but not fuzzed.

**Reviewed and found sound** (with tests): container ids never come from user input (queue job names are allowlist keys; unknown names never reach the autoloader); generated PHP (ORM, queue, routes, container) validates every name placed in code positions (`CodeNames`) and uses `var_export` for values; no `eval`, `unserialize`, shell strings (only array-form `proc_open` in one class); SQL is bound and identifiers validated; error responses never contain internals in production (three formats, both modes); logs are redacted and sanitised with fuzz tests that found and fixed two leaks during this work.

## 8. Compiler assessment

**Is the current compiler architecture worth continuing? Yes, for validation, determinism and the production guarantee; not (yet) for speed.**

Compiled today: container, route table and argument plans, module list, config, Tusk templates, ORM metadata and hydrators, queue codecs and invokers. Generated code is deterministic (tests compare output), free of `eval`/reflection, written to a staging directory and swapped atomically, and every contributor's errors are reported together.

Keep compiling: anything that turns a runtime failure into a build failure (container graph, routes, ORM maps, job classes, config validation) and anything that removes reflection from the request path (route argument plans, hydrators, codecs).
Do **not** compile: per-request data, Tusk dev caching (already on-demand), config that is cheap to read, anything whose only benefit is theoretical. Add a benchmark gate before compiling anything new.

Open improvement: measure a realistic large application (F-section §6) before making any speed claim, and add build-artifact permissions and secret handling (F4).

## 9. Developer experience assessment

**Already good**: familiar constructor DI with actionable messages ("Cannot resolve X. Fix: bind it"), `trunk new` profiles (api, web, self-contained, cli, worker) that are just module lists, `trunk doctor`, `trunk make:*`, `.env` with real environment precedence, plain PSR interfaces (PSR-3/7/11/15/16), no facades or global helpers, native PHP everywhere (entities, jobs, controllers are ordinary classes), errors that explain the fix, production fails closed with a clear message.

**Friction**: the vocabulary is growing (module, capability, profile, integration, scope, tag, map, job, artifact); two ways to enable things (`trunk.php` and `package:install`); route groups without middleware; console contracts hidden inside an optional package; Tusk runtime errors without source lines; `build/` must be re-run after config/.env changes (stated by the command, easy to forget).

## 10. What NOT to build yet

* **Auth**: not before route-group middleware (F7), module dependencies (F5) and the console/core contract split (F1). Auth would otherwise invent private mechanisms for all three.
* **Mail, storage, events, scheduler, WebSockets**: none until the ecosystem contract (package metadata, integration modules, scoped ids) has been used by one external package.
* **More ORM features** (locking, composite keys, polymorphism): not until core packaging (F2) is settled; the ORM is already ahead of the rest.
* **Queue drivers (Redis/RabbitMQ)**: not until the queue's public contracts (`QueueDriver`, `Job`) have had a real user.
* **OpenTelemetry SDK adapter**: as a package, later; the seams exist.
* **A new package system or plugin manager**: the capability mechanism is enough; harden it (F5) instead.
* **More compiler passes**: none without a benchmark showing value.

## 11. Recommended next sequence

1. **Fix the core boundary**: move console contracts to core (F1); make `trunkphp/framework` minimal and drop HTTP/PDO/cache requirements from it (F2); trim `console`'s composer requirements (F3).
2. **Build artifact hygiene**: `0640/0750` permissions and a runtime-secret mechanism (F4).
3. **Module dependencies and ordering** with build-time cycle detection (F5).
4. **HTTP hardening**: body size limit, bounded JSON body reader, upload policy (F6), with security tests.
5. **Router**: group and route middleware (F7).
6. **Benchmark a realistic application** and publish the method and numbers; decide what the compiler advertises (§6, §8).
7. **Tusk source mapping** for runtime errors (F8).
8. **Package a first external-style capability** (for example a tiny third-party-shaped package in the repo) to prove the ecosystem contract.
9. Only then: **auth**, built as an ordinary optional package on the contracts above.

## 12. The ten-year question

**What I would change now**: the packaging boundary (F1, F2), because it is cheap now and expensive once versions are published; the secret handling of the build (F4), because it is a security default that people will copy; module dependency metadata (F5) and group middleware (F7), because every future package will need them and will otherwise fork the pattern; request limits (F6).

**What I would deliberately leave alone**: the constructor-injection container and its compile-time checks; the "modules are plain classes, capabilities are metadata" model; PSR-first HTTP; the no-facade/no-global-helper rule; interfaces-not-base-classes for errors and lifecycle; the ORM/queue/database layering (optional, one-directional); PHPStan max with zero suppressions; the rule that compilation must earn its place with validation or a measurement. And I would *not* add features while F1 to F7 are open.

## 13. Architectural fitness answers

* **A. Core without Auth, ORM, Cache, Queue, Mail, Storage, Tusk?** Yes at the code level (nothing in `src/` imports them; a CLI project runs with only `logging` and `console`). Not yet at the *package* level because the root package requires HTTP/PDO/cache libraries (F2).
* **B. Can each be replaced by the developer's own implementation?** Yes where a contract exists: `Psr\SimpleCache\CacheInterface`, `QueueDriver`, `LoggerInterface`, `Metrics`, `Tracer`, `ErrorRenderer`, `ErrorReporter`, `HealthCheck`, `MappingRegistry`. The ORM is not an interface (you would replace it wholesale; Doctrine can sit on `Connection`'s PDO or its own). Auth has no contracts yet.
* **C. API, Web, CLI, Worker, mixed without unnecessary dependencies?** Yes: profiles are module lists; verified by end-to-end tests for all five. Extra required PSR packages in core (F2) are the only leak.
* **D. Dynamic in development, compiled in production?** Yes; same modules, `Application` chooses the container, production refuses to run without a build; dev/compiled parity is tested for container, kernel, ORM, queue, health.
* **E. Can the compiler meaningfully improve startup/runtime speed?** Not demonstrated here (§6). It meaningfully improves *safety*. Needs a large-app benchmark.
* **F. Understandable by reading normal PHP?** Yes: controllers, services, entities, jobs and maps are ordinary classes; generated code is inspectable.
* **G. Secure without developers knowing every mechanism?** Largely yes (bound SQL, escaped templates, redaction, generic errors, validated names), with the gaps in F4 and F6.
* **H. Can optional packages evolve independently?** Mostly; blocked by F1 (console contracts) and by the shared root version.
* **I. Third-party packages without modifying Core?** The mechanism exists and is tested (capability metadata, integrations, build contributors, tags); module dependencies (F5), scoped-id contributions (F2) and group middleware (F7) are needed for it to scale.
* **J. Realistic to scale to a serious open-source ecosystem?** Yes if F1 to F7 are done first; the foundation (DI, compiler, error/log/lifecycle, layering) is sound.
