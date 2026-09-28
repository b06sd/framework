# Elegance and production-readiness gap analysis

Question asked: how elegant is Trunk to actually use for a production project, not just to read about. Method: build something real. In a throwaway project (`trunk new shop --type=api`), I installed `console`, `database`, `orm`, `auth`, `cache`, `queue`, `validation`, `observability` and `health` together, wrote one feature end to end (a `Product` entity, a validated create endpoint, a queued side effect, a cache-aside read), ran it over real HTTP, and read the code for anything the docs don't show. No code was changed for this review; findings are graded by what a production team would actually hit, not by theoretical completeness.

## What is genuinely elegant (verified, not assumed)

* **One feature, six small files, no ceremony.** The whole vertical slice — migration, entity, map, request class, job, controller — was about six short files, all generated or hand-written, no XML/YAML/service-provider registration. The controller injected six services from five different packages (`EntityManager`, `RequestValidator`, `Queue`, `CacheInterface`, `Auth`, `ResponseBuilder`) through a plain constructor, and it was wired automatically the moment each capability was `package:install`ed. Nothing had to be told about anything else.
* **Config is uniform.** Every capability's config file lives at `config/<id>.php`, every one is a plain function reading `Runtime`, every secret goes through `secret()` and every setting through `variable()`. There is no capability that does config differently.
* **Errors are one shape everywhere.** A validation failure, a 404, a database error and an unhandled exception all come back as `{"error":{"code","message","requestId",...}}`, with a request id on every single response I generated, success or failure. A team building alerting or client error-handling only has to learn this once.
* **`trunk build` is safe to run on a live server.** It writes to a temporary `build.building-<random>` directory and only `rename()`s it into place once everything compiles (`src/Compiler/Build/BuildRunner.php:83-100`), with the previous build kept and restored if the swap fails. This is real zero-downtime-safe behaviour — but it is not written down anywhere; `docs/deployment.md` never tells an operator this is safe to do, so a cautious team would assume they need to take the app down to rebuild.
* **The CLI is one shape.** Every command is `noun:verb` or a bare verb (`queue:table`, `migrate:status`, `make:request`, `package:install`), never a mix. `trunk doctor` and `trunk list` both stayed usable even with nine capabilities installed at once.

## Gaps that would actually bite a production team

### 1. No feature-testing kit — every app hand-rolls its own kernel harness
`docs/testing.md`'s own example for testing your application is: construct a `ProjectLoader`, an `ApplicationFactory`, a `Runtime` with overrides, a `HttpKernelFactory`, then build a raw `Trunk\Http\Message\ServerRequest` by hand and assert on `getStatusCode()`/`getBody()` yourself. There is no public `TestCase` base, no HTTP test client, no `->post()->assertStatus()->assertJson()`, and nothing for the auth flows this framework spends the most effort on: no helper to log a test user in, carry the session cookie, or fetch a CSRF token before posting a form. Every project reinvents this, and the reinvention is exactly the boilerplate the framework refuses to have anywhere else. This is the single biggest gap between "elegant to build a feature" and "elegant to keep a feature working."

*Where it shows up*: the moment a team writes their second feature test, not their first.

### 2. `trunk doctor` says "healthy" while a wired capability is one request away from a 500
I enabled `queue`, wrote a controller that calls `$queue->dispatch(...)`, ran `trunk doctor`, and got `✓ Project is healthy.` I had not run `trunk queue:table && trunk migrate`. The first real request hit `SQLSTATE[HY000]: no such table: trunk_jobs`, surfaced through the generic pipeline as a `500 DATABASE_ERROR` — correct behaviour for hiding a database error from a client, but exactly the kind of thing `doctor`'s stated job ("Check that this project is healthy") should have caught before it reached a request at all. Reading `DoctorCommand.php`, its checks are all static: file permissions, capability wiring, PHP version, build freshness. It never opens a database connection or asks a capability "do your tables exist yet?" `auth` has the identical shape (`auth:table` then `migrate`, two steps, nothing checks it ran).

*Fix direction*: let a capability contribute an optional runtime check to `doctor` (a small interface, the same "modules opt in" pattern already used for `BuildContributor` and `trunk.health_check`), so `queue` and `auth` can each say "my tables aren't there."

### 3. No general-purpose rate limiting
The only rate limiting in the framework is inside `auth` — login attempts and anonymous session creation (`AttemptCounter`, `packages/auth/src/Throttle/`). There is nothing an application can put on an arbitrary API route ("100 requests/minute per key"), which is one of the most common things a production API needs on day one. `AttemptCounter`'s atomic update-then-insert pattern already does the hard part; it just isn't exposed as a reusable middleware outside `auth`.

### 4. No CORS support
Grepped the whole codebase: the only mention of CORS is a docblock comment in `HttpKernel.php` using it as a hypothetical example of "middleware you might add." `SecurityHeaders` got a dedicated, documented, opt-in middleware (`packages/http/src/Security/SecurityHeaders.php`); CORS — needed by essentially every browser-facing API — got nothing equivalent. An app has to write its own from scratch, with no framework-provided pattern to follow.

### 5. No task scheduler
`docs/deployment.md`'s answer to recurring jobs is "cron: `trunk auth:prune`" — a raw crontab line per task. As an app grows past one or two scheduled jobs, this becomes N crontab entries to keep in sync with the codebase, invisible to `trunk doctor`, `route:list`, or anything else that shows what an app does. There's no `trunk schedule:run` plus a fluent `daily()`/`hourly()` definition, the way jobs and routes are each defined once, in code, and discoverable.

### 6. No seeders or factories
No `db:seed`, no `make:seeder`, no test-data factory tied to entities. For local development, staging resets, and CI fixtures this is normally load-bearing infrastructure; here it's entirely ad hoc — a team writes and maintains their own one-off scripts, with no framework convention to plug into (compare to how consistently everything else — jobs, requests, entities — has exactly one blessed shape).

### 7. Multi-server cache is a silent trap
`cache` ships `array`, `file` and `null` stores only. `queue` is database-backed (safe across servers) and sessions are database-backed (safe across servers), but cache is not — a `CACHE_DRIVER=file` app that scales past one web server gets a different cache per server with no error, no warning, and stale-looking data that's actually just per-instance. Nothing in `doctor` or the docs flags this. It's the one capability whose default silently stops being correct exactly when a production app grows, rather than failing loudly.

## Net assessment

For a single-server or single-process production deployment, Trunk's ease of use is close to what it aims for: one way to do most things, config and errors that never surprise you, and real DI composition across independently-installed packages that held up under a six-service controller with zero manual wiring. The gaps above are almost all about the *second* week of running a production app, not the first: testing the app you just built, knowing a capability is actually ready before it's live, and the handful of "every real API needs this" pieces (rate limiting, CORS, scheduling, seeding) that Laravel-coming developers will reach for and not find.

None of these are architectural conflicts with Trunk's rules — a test kit, a doctor check interface, rate-limit middleware, CORS middleware and a scheduler can all be built the same way `auth` and `validation` were: explicit DI, no base classes, compile-first where it matters. I haven't sized or sequenced them here; that's a roadmap decision, not a review one.
