# Trunk: architecture and foundation review, second pass

Reviewed after Section 17 (findings F1 to F11 of the first review). Everything below was re-derived from the code and from commands run for this pass; nothing is carried over from the first review's conclusions. Labels: **Confirmed** (observed or measured this pass), **Likely** (reasoned, not measured), **Needs verification** (could not be checked here).

Environment: macOS, PHP 8.5.5 only (CLI, NTS). PHP 8.4, the declared minimum and the version CI uses, is not installed here.

## 1. Executive assessment

| Area | Rating | Basis |
| --- | --- | --- |
| Internal architecture (layering, DI, compiler, error/log/lifecycle) | **Strong** | Confirmed: dependency graph below is acyclic and core imports no package; `Architecture` suite enforces it; PHPStan max clean with no suppressions; 1308 tests pass (6 skipped without live DBs; 0 skipped with them). |
| Performance | **Good, and cheap to keep** | Confirmed (numbers in section 6): ~14.5 µs warm request, 0 bytes growth over 20,000 requests. |
| Security of what exists | **Good** | Confirmed: real build is 0640/0750, `.env` 0640, secrets not compiled, request limits, untrusted forwarded headers. Auth/sessions/CSRF do not exist yet. |
| **Distribution and install path** | **Broken for HTTP projects** | **Confirmed regression from the first review's F2 fix (R1).** A real `composer install` of a scaffolded API project fatals on the first request. |
| Delivery pipeline (CI) | **Weak** | Confirmed: one PHP 8.4 job, no live DB, no install test (R2). |
| Public API / compatibility story | **Undefined** | Confirmed: 47 interfaces, 5 abstract classes, 7 `@internal`/`@api` marks in total (R5). |

The design is sound. The one serious problem is one I introduced: the first review's F2 fix made the root `composer.json` minimal but left the root autoloading every package, so a consumer no longer receives the PSR packages the HTTP package needs. My verification at the time used a stand-in autoloader and could not have seen it. That is the main finding of this pass.

## 2. Current architecture (as re-discovered)

* **Core** (`src/`, 119 files, 6,407 lines): Application, Compiler, Container, Contracts (module, build, console, dependencies), Error, Foundation (config, runtime, manifest, capability catalog, project), Health, Lifecycle, Logging, Observability (interfaces plus no-op defaults), Support.
* **Packages** (`packages/*/src`): http (47 files), router (19), tusk (44), mvc (3), console (25), cache (13), database (48), orm (41), queue (51), observability (7). `packages/auth` is empty.
* **Tests**: 240 files, 20,688 lines (about 0.76 lines of test per line of source, 27,200 lines); suites: Unit, Integration, Security, Performance, Architecture.
* **Model**: capabilities (metadata) versus modules (plain `register`/`boot` classes ordered by `trunk.php`); `trunk build` compiles container, routes, pipeline, config, views, ORM and job maps; production fails closed without `build/`.

## 3. Core boundary report

Unchanged in substance from the first review and now test-enforced. Two observations:

* **Confirmed, boundary is honest at source and metadata level**: root `require` is `php`, `psr/container`, `psr/log`; every package requires exactly the `trunk/*` packages it imports (`ArchitectureRulesTest`).
* **Confirmed, boundary is dishonest at distribution level** (R1): the root `autoload` maps all ten package namespaces, so "core" is minimal in metadata but ships every package's code and none of their third-party requirements.

## 4. Dependency graph (recomputed from every `use Trunk\...` import)

```text
core          -> (nothing)
router        -> core (5)
http          -> core (71), router (15)
tusk          -> core (16)
mvc           -> core (5), http (6), tusk (5)
console       -> core (122), router (3)
cache         -> core (15)
database      -> core (38)
orm           -> core (36), database (9)
queue         -> core (79), database (8)
observability -> core (27)
```

No cycles; no package imports console; `console` is imported by nothing. `composer.json` requirements of every package match these edges (no `trunk/http` on console any more).

## 5. Findings

### R1 (P1, Confirmed): a real install of an HTTP project has no PSR-7/15 packages

*Evidence.* `trunk new real-api --type=api --repository=<checkout>`, then `composer install`. Composer installed `psr/container`, `psr/log`, `trunk/framework` and PHPUnit, and **no** `psr/http-message`, `psr/http-factory`, `psr/http-server-*`. `trunk build` succeeded. The first request then failed: `Fatal error: Uncaught Error: Interface "Psr\Http\Server\MiddlewareInterface" not found in packages/http/src/Middleware/ErrorHandlingMiddleware.php:33`. The same holds for anything needing `psr/simple-cache` (cache) or `ext-pdo` (database).

*Cause.* The first review's F2 moved those requirements from `require` to `require-dev` in the root, correctly for a minimal core, but the consumer installs `trunk/framework` (whose autoload includes every package) and nothing else. The package `composer.json` files that do declare the PSR requirements are never installed separately, because the packages are not published or split. The end-to-end tests use a stand-in `vendor/autoload.php` that borrows the monorepo's own vendor directory, so they see every dev dependency.

*Why it was missed.* My Section 17 verification claim ("a scaffolded CLI project that never loads an HTTP class") was checked through that stand-in. `trunk doctor` also reports "Composer dependencies installed" as passing for this project (R3).

*Fix (recommended).*
1. Give each built-in capability a `composer` requirement list in its metadata (`http`: the four `psr/http-*`; `cache`: `psr/simple-cache`; `database`: `ext-pdo`), used by `trunk new` (profile decides) and by `trunk package:install` (runs `composer require` for missing ones, through the existing allow-listed launcher).
2. Add a CI job that scaffolds each profile, runs a real `composer install`, builds, and issues a request (or runs a command). This is the test that would have caught R1.
3. Longer term: publish the packages as separate Composer packages and make `trunk/framework` truly minimal. Until then the honest alternative is to put the PSR packages back in the root `require`.

Not changed in this pass (review only): this needs your decision on 1 versus 3.

### R2 (P1, Confirmed): CI cannot catch this class of problem

`.github/workflows/ci.yml` runs one job on PHP 8.4: validate root only, audit, format, PHPStan, PHPUnit. Missing: PHP 8.5 (the version all development ran on), per-package `composer validate --strict`, MySQL and PostgreSQL services (so the 6 live-database tests are skipped in CI and the MySQL and PostgreSQL drivers are only ever exercised locally), a lowest-dependencies run, and the real-install job from R1. **Needs verification**: the code has only ever been executed on PHP 8.5.5 here; nothing in it uses 8.5-only syntax that I could find (searched for the pipe operator, `array_first`/`array_last`, `#[NoDiscard]`), but I could not run 8.4 to prove it.

### R3 (P2, Confirmed): `trunk doctor` does not verify that enabled capabilities can load

For the project in R1, doctor printed "Composer dependencies installed" as OK. It should check that the key classes of each enabled capability exist (for example `Psr\Http\Message\ServerRequestInterface` for `http`) and name the missing Composer package. Cheap; follows from R1's metadata.

### R4 (P2, Confirmed): the `logging` capability also owns error handling, lifecycle and health

`LoggingModule` binds `LoggerInterface`, but also `ExceptionHandler`, `LifecycleManager` and `HealthChecker`. That is why `health` and `queue` "require logging" and why a project cannot enable the error pipeline without something named logging. It works, but the name will mislead third-party module authors and locks the responsibility into one module. Suggest splitting the non-logging bindings into a `RuntimeModule` (error pipeline, lifecycle, health checker) that `logging` depends on, or renaming the capability. Do it before other packages start depending on the current shape.

### R5 (P2, Confirmed): no defined public API or compatibility boundary

There are 47 interfaces and 5 abstract classes (`CompiledContainer`, `Grammar`, `SchemaGrammar`, `Message`, `RequestMessage`), and only 7 `@internal`/`@api` marks in total. The stated rule is "no base classes to extend", but nothing marks these abstract classes as internal, and nothing says which classes an application may depend on. Before a first published version, mark each namespace as public API or `@internal`, and decide the versioning policy (there is no root version at all: Composer warns "could not detect the root package version").

### R6 (P3, Confirmed): module instantiation is repeated at 12 sites

`new $class()` for every module in `trunk.php` appears in Application, ArtifactWriter, BuildRunner, ModuleGraph, CapabilityManager, HttpArtifactBuilder, HttpKernelFactory, RouteArtifactWriter, RouteListCommand, ApplicationCommands and ConsoleModule (and the ORM instantiates maps). Each iterates the manifest independently. Not a bug; a single `ModuleInstances` helper would give one place to add constructor-failure handling (a module whose constructor throws currently fails differently in each command).

### R7 (P3, Confirmed): scaffolded `storage/` and `storage/logs` are 0755

A real scaffold gives `storage` and `storage/logs` mode 0755 while the build directory is 0750 and log files are 0640. Log content is redacted, but directory listing (file names contain dates) is world-readable. Create them 0750 in the scaffolder; the development view cache and other generator `mkdir` calls are 0755 and harmless (development only, no secrets).

### R8 (P3, Confirmed): project hygiene

No `SECURITY.md` (how to report a vulnerability), `CONTRIBUTING.md`, `CHANGELOG.md` or `UPGRADE.md`; empty `tools/` and `skeleton/` directories containing only `.gitkeep`; no monorepo split tooling. Fine for a private prototype; needed before publication.

### R9 (P3, Confirmed, informational): error-suppression operator

The `@` operator appears at 12 call sites (file operations that already handle the failure). Consistent with correct fallbacks, but it is at odds with the project's zero-suppression stance for static analysis; either accept it explicitly or replace with `set_error_handler`-free checks.

### Things I looked for and did not find (Confirmed)

Global functions, static properties or static caches in `src/` and `packages/`; `eval`, `unserialize`, `shell_exec`, `system`, `passthru`, `popen` (the one `proc_open` is the allow-listed launcher); weak randomness (`rand`, `mt_rand`, `uniqid`) or weak hashing outside `sha1` used only for cache file names; dead classes (the one apparent orphan, `ConsoleKernelFactory`, is used by `bin/trunk`).

## 6. Performance (measured this pass, this machine, PHP 8.5.5, medians)

| Measurement | p50 | p95 | p99 |
| --- | --- | --- | --- |
| Bootstrap in-process, development / compiled container | 62.3 / 62.6 µs | 77.9 / 77.4 µs | 122 / 125 µs |
| Simple request, development container | 14.6 µs | 18.5 µs | 26.3 µs |
| Simple request, compiled container | 14.5 µs | 17.7 µs | 23.4 µs |
| 404 request (exception, handler, renderer) | 42.7 µs | 52.3 µs | 75.0 µs |
| Route match static / dynamic / miss (1,000 routes) | 0.4 / 3.1 / 2.0 µs | | |
| Memory growth over 20,000 requests | 0 bytes (peak 22 MB) | | |
| Request without / with logging, request id, lifecycle | 14.4 / 15.2 µs | | |
| Request with metrics and span logging on | 38.6 µs | | |
| Failing request (log plus generic 500) | 45.0 µs | | |
| `logger.info` / record below threshold | 8.1 / 0.1 µs | | |
| Cold PHP process per request, local / production | 60.2 / 59.2 ms | 65.6 / 76.9 ms | 70.3 / 77.0 ms |
| SQLite select: raw / builder / ORM readOnly | 2.3 / 5.8 / 18.5 µs | | |

Reading it honestly: Section 17 added request limits, route middleware, trusted-proxy parsing and a fiber-aware context holder, and the warm request path did not move measurably (14.4 to 14.5 µs). Turning metrics and tracing on costs about 23 µs per request (up from ~18 in the first review, because span records now go through the redacting logger). Compiled and development containers are still indistinguishable at this scale; compilation's demonstrated value is validation, not speed. **Needs benchmarking**, unchanged: a realistic large application, throughput under php-fpm, comparison with other frameworks (none installed here; no relative claim is made). `InMemoryMetrics` is per process, so under php-fpm each worker reports only its own requests (**Likely** limitation, documented in the class).

## 7. Security

Confirmed working: real build directory 0750 with 10 files at 0640 and `.env` 0640; secrets resolved from the environment at run time and not compiled (`SecretsEndToEndTest`); request body, upload and JSON limits (413/415/400) with a 400-case upload fuzz test; forwarded headers ignored unless `http.trusted_proxies` lists the TCP peer (default empty); errors never expose internals in production; log lines cannot be forged; `composer audit` reports no advisories.

Not built, so not assessed: authentication, sessions, CSRF, password hashing, CORS, rate limiting. Responses carry `X-Content-Type-Options: nosniff` and error pages a CSP, but there is no default security-header middleware (HSTS, frame options). Decide these with auth, not before. R7 is the only permissions issue found.

## 8. Compiler assessment

Unchanged and verified by tests: it earns its place through aggregated build-time errors (missing dependencies, cycles, captive dependencies, duplicate routes, invalid maps and jobs, bad config, bad module order, bad trusted proxies) and the fail-closed production guard. One shared loader (`ArtifactLoader`) now serves four artifact kinds. The compiled-artifact path is still exercised only in-repo, which is R1's root cause; the compile step is fine, the install step is not.

## 9. Developer experience

Good: actionable errors, `doctor`, `package:install`, module-order errors that name the fix, the dev error page now showing template source. Gaps: R3 (doctor blind to missing packages), R1 (first-run failure for a new user is a raw PHP fatal instead of an actionable message; the compiler could refuse to build when a capability's classes cannot load).

## 10. What not to build yet

Auth, until R1, R4 and R5 are settled: auth will be the first large package added by someone who did not write the core, and it will copy whatever the packaging and module shape is today. Also not yet: more database drivers, Redis or RabbitMQ queue drivers, a scheduler, an OpenTelemetry SDK bundle.

## 11. Recommended next sequence

1. **R1 and R2 together** (P1): capability composer requirements, `trunk new` and `package:install` honouring them, real-install CI job per profile, PHP 8.4 and 8.5 matrix, DB services, per-package validate. Verify by running the R1 reproduction again.
2. R3 (doctor checks) as part of the same change.
3. R4 (split logging from runtime bindings) and R5 (public API marks and version policy), before any third-party package exists.
4. R7, R8, R6, R9 as a small cleanup section.
5. Then auth.

## 12. The ten-year question

**What I would change now:** the distribution story (R1). It is cheap to fix today and expensive after the first release, and it is the only defect that stops a new user from getting a working project. Then the API boundary (R5) and the logging/runtime split (R4), since both are impossible to change without breaking early adopters.

**What I would deliberately leave alone:** the container and its compile-time checks; capabilities as metadata and modules as plain classes; PSR-first HTTP; no facades or globals; interfaces instead of base classes for errors and lifecycle; PHPStan max with no suppressions; the Architecture suite (it is the mechanism that keeps the first review's fixes from drifting back).

**Lesson for the process:** my own verification was the weak point this time. Tests that stand in for the install step (a borrowed autoloader) cannot prove the install step works. The R1 reproduction (real `composer install`, real request) belongs in CI.

## 0. Resolution of R1 to R3 (done after this review)

* **R1 fixed and proven.** Built-in capabilities declare their Composer requirements (`Capability::$composer`; third-party metadata is ignored). `trunk new` writes them into the project, `package:install` installs missing ones in one `composer require` before editing `trunk.php`, `build` refuses when they are missing. New `tests/Install/RealInstallTest.php` (suite `Install`, `composer test:install`) scaffolds api, web, self-contained, cli and worker, runs a real `composer install --no-dev` from a path repository, then the project's own `vendor/bin/trunk` doctor, build, list and a production request; a further test enables `cache` in a fresh project and checks the package lands in `composer.json` and `vendor/`. All 6 pass (about 35 s). To prove the test can see the bug, I removed the HTTP requirement temporarily: the api and web cases failed with the original `Interface "Psr\Http\Server\MiddlewareInterface" not found`; the requirement is restored.
* **R3 fixed.** `trunk doctor` has a "Capability dependencies" check that names the missing packages and the exact `composer require` line; the stand-in project used by the other end-to-end tests now carries an honest `installed.json`.
* **R2 partly fixed** (workflow only; not run here because there is no CI runner): PHP 8.4 and 8.5 matrix, MySQL and PostgreSQL services so the live-database suites run, `composer validate --strict` for the root and every package, a `--prefer-lowest` job (I ran that locally: 1314 tests pass), and a real-install job on both PHP versions. Whether the workflow is green on GitHub is **unverified**. PHP 8.4 itself still could not be run locally.
* **R4 fixed.** `logging` now provides only the PSR-3 logger. The error pipeline (`ExceptionHandler`), lifecycle reset (`LifecycleManager`), health checker and no-op metrics/tracer defaults are the new `diagnostics` capability (`DiagnosticsModule`, requires logging, owns `config/errors.php` and the `errors.format` build check). `health` and `queue` require `diagnostics`; profiles list it. Migration note in `UPGRADE.md`.
* **R5 fixed.** `docs/API.md` defines the policy and lists the 126 public types, all tagged `@api` in source. `tests/Architecture/PublicApiTest.php` enforces: `@api` types are interfaces, enums or final classes; the five abstract classes are `@internal`; no type is both; no `@api` signature exposes a non-`@api` type (this found 29 leaks: constructors wired by the container and a few accessors are now tagged `@internal`, `ModuleManifest` and `DebugInfo` became API); `docs/API.md` cannot go stale. Versioning: lockstep semver, 0.x may break API in a minor release with an `UPGRADE.md` entry, deprecate one minor before removal from 1.0; `branch-alias` `0.1.x-dev` added to every `composer.json` so the `^0.1` constraints resolve. Added `CHANGELOG.md` and `UPGRADE.md`; removed the empty `tools/.gitkeep` (`tools/api-list.php` now lives there).
* Auth (Section 20) was built on top of these fixes; its public types are in `docs/API.md` under "Auth".
* R6 to R9 are untouched (R8 is partly addressed: `CHANGELOG.md`, `UPGRADE.md`; still no `SECURITY.md`, `CONTRIBUTING.md`, `skeleton/`).

## 13. Status of the first review's findings after this pass

| Finding | Status now |
| --- | --- |
| F1, F3 to F11 | Still hold; enforced or covered by tests. |
| **F2** (minimal core distribution) | Metadata fix held but the consumer result was broken (R1). **Now resolved**: capabilities declare Composer requirements and a real-install test proves a fresh project works. |
