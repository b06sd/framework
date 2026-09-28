# Roadmap

Where Trunk is, what happens in the next 2-4 weeks, and what the rest of the year looks like. This is a living plan, not a promise: each "Now" block gets re-decided when it's actually reached, and dates are relative to when a block starts, not fixed calendar dates.

**Decisions this roadmap assumes** (yours, made 2026-09-25 — revisit them here if they change):
* Trunk stays **0.x through the rest of this year**. No API freeze is planned; `UPGRADE.md` keeps recording breaking changes in minor releases.
* Trunk stays a **personal framework** for now: no `CONTRIBUTING.md`, no docs site, no community channel this year. Docs keep being written for you and future-you.
* Priority is **fixing and finishing what exists** over starting new capabilities, until the current cleanup is done.

## Where we are (main @ `07340a5`, v0.1.3, 2026-09-28)

* **Published**: `trunkphp/framework` v0.1.3 on Packagist, installable with `composer global require trunkphp/framework`. CI (quality, lowest-deps, real-install, real-browser, on PHP 8.4 and 8.5) is green on GitHub on both the `main` push and the `v0.1.3` tag — six jobs, confirmed on every push since v0.1.0. Verified with a genuine clean-room install (`composer global update trunkphp/framework`, `trunk new`, `composer install`, `trunk doctor`, `package:install schedule`) after the tag landed on Packagist.
* **Capabilities**: `http`, `logging`, `diagnostics`, `tusk`, `mvc`, `console`, `cache`, `database`, `orm`, `queue`, `schedule`, `rate-limit`, `observability`, `auth`, `health`, `validation`. Sixteen capabilities, fifteen packages plus core.
* **Tests**: 1,841, 0 skipped with live MySQL, PostgreSQL and Redis (26 skip without them); `composer audit` clean; PHPStan max, zero suppressions.
* **This release**: closed all seven gaps from `docs/ELEGANCE_REVIEW.md` — `trunk doctor` now checks a capability's own tables actually exist; a Redis cache driver; CORS support; the `rate-limit` capability; a real test client (`Trunk\Testing`); ORM factories and seeding (`make:factory`, `db:seed`); the `schedule` capability. Also: the three pending fixes from the last review, and the `docs/ARCHITECTURE_REVIEW_2.md` small-gap cleanup (stale CI doc lines, `storage/` permissions, the `@`-suppression exception documented, the empty `skeleton/` directory removed, a `ModuleInstances` helper replacing 10 of 12 duplicated `new $class()` call sites).
* **Deliberately not built**: auth extras (remember-me, password reset, email verification, 2FA, OAuth/OIDC, WebAuthn — the two mail-dependent ones are blocked on a `mail` capability that doesn't exist yet), a `mail` capability itself, additional queue drivers, job batches/chains, payload encryption, ORM lock-for-update/composite keys/polymorphic relations, database-backed validation rules, an OpenTelemetry SDK adapter, Docker support (no `Dockerfile`/`docker-compose.yml`, never asked for).
* **Never verified**: behaviour under a real production stack (php-fpm+nginx, FrankenPHP, RoadRunner — everything so far has run under `php -S` or PHP's built-in server, and there's no local nginx/FrankenPHP to test against); Firefox and Safari (the browser suite only drives Chrome); PHP patch levels other than 8.4.25 and 8.5.5; performance on hardware other than the one laptop everything was benchmarked on.

## Now: pick the next block

The v0.1.3 cleanup block is closed. Two items from it were deliberately not done and need a decision before the next block starts:

1. **Verify a real production deploy once**, at least minimally: php-fpm behind nginx (or FrankenPHP) serving a built project, confirming `HEAD` has no body, security headers are present, and the generic-error behaviour holds outside `php -S`. Skipped this block for lack of the infrastructure locally; still open.
2. Everything else from the review is done. Time to re-read "Rest of the year" below with fresh eyes and pick what's next.

## Rest of the year: candidate capabilities, revisited each quarter

Because the goal is "stay 0.x, keep building" without a fixed 1.0 deadline, this section is a **prioritized backlog**, not a committed schedule. When the "Now" block above finishes, come back here, re-read this list with fresh eyes, and pick what's next — the order below is a starting recommendation based on what unblocks the most, not a fixed sequence.

**Likely next (highest leverage):**
* **`mail` capability** — a `Mailer` interface, at minimum an SMTP transport and a log/file transport for development (matching the cache/queue pattern of a real driver plus a null/array one for tests), templated via Tusk. This is the one piece that unblocks two already-designed-for auth features (password reset, email verification) rather than starting them from nothing.
* **Auth: password reset and email verification**, once mail exists. These have been "deliberately unbuilt" since auth shipped, specifically because they need mail.

**Worth doing, no hard dependency:**
* **A second queue driver** (Redis is the obvious first choice) — proves the `Driver` interface generalizes past the database driver, and matters if a real project ever needs it.
* **Auth: 2FA (TOTP)** — self-contained, no external dependency beyond a TOTP algorithm; remember-me cookies are a smaller version of the same session work.
* **ORM: composite keys or lock-for-update**, whichever a real project actually needs first — these were deferred for lack of a concrete use case, not difficulty, so let real usage decide which to build.

**Lower priority / opportunistic:**
* OAuth/OIDC and WebAuthn (bigger surface, no immediate need signalled).
* Job batches/chains, payload encryption for the queue.
* An OpenTelemetry SDK adapter package (the seams already exist: `Tracer`, `Metrics`).

**Ongoing, not a milestone:** every capability that already exists keeps getting the same treatment the last review gave — fuzz and adversarial tests, measure, fix only what a failing test or a measurement justifies. Revisit `docs/HARDENING_REPORT.md`-style passes for `auth`, `orm`, `queue`, `validation` on a loose cadence (e.g., whenever a capability hasn't been touched in a while and something changed around it).

## What stays off this roadmap this year

Per the audience decision above: `CONTRIBUTING.md`, a docs site, community channels, and any external-adoption polish (badges, comparison pages, a project website). None of this blocks using Trunk yourself; revisit only if the "stays mine for now" decision changes.

## How this gets tracked

* This file is the single source of truth for "what's next" — update it at the end of each block rather than letting the plan live only in chat history.
* A block ends with a release (v0.1.3, v0.1.4, ...) or a deliberate "not now" decision, never silently.
* If a "Now" block's scope changes mid-way (a review turns up something bigger), that's a reason to update this file, not to quietly expand the block.
