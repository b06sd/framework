# Roadmap

Where Trunk is, what happens in the next 2-4 weeks, and what the rest of the year looks like. This is a living plan, not a promise: each "Now" block gets re-decided when it's actually reached, and dates are relative to when a block starts, not fixed calendar dates.

**Decisions this roadmap assumes** (yours, made 2026-09-25 — revisit them here if they change):
* Trunk stays **0.x through the rest of this year**. No API freeze is planned; `UPGRADE.md` keeps recording breaking changes in minor releases.
* Trunk stays a **personal framework** for now: no `CONTRIBUTING.md`, no docs site, no community channel this year. Docs keep being written for you and future-you.
* Priority is **fixing and finishing what exists** over starting new capabilities, until the current cleanup is done.

## Where we are (main @ `23e7141`, v0.1.2, 2026-09-20)

* **Published**: `trunkphp/framework` on Packagist, installable with `composer global require trunkphp/framework`. CI (quality, lowest-deps, real-install, real-browser, on PHP 8.4 and 8.5) is green on GitHub, not just local — six jobs, confirmed on every push since v0.1.0.
* **Capabilities**: `http`, `logging`, `diagnostics`, `tusk`, `mvc`, `console`, `cache`, `database`, `orm`, `queue`, `observability`, `auth`, `health`, `validation`. Thirteen packages plus core.
* **Tests**: 1,732 across Unit (1,122), Integration (359), Security (220), Performance (13), Architecture (9), Install (9); `composer audit` clean; PHPStan max, zero suppressions.
* **Pending, uncommitted**: three small fixes from the last review (the `make:request` hint, the validation guide's Tusk `default('')` correction, `RequestValidator`'s `@throws` docblock) — held back on purpose to accumulate toward v0.1.3 rather than releasing one at a time.
* **Known small gaps found in review**: all done now except no `CONTRIBUTING.md` (not needed this year per the decision above). The rest this line used to list (stale CI doc lines, `storage/` scaffolded at `0755`, unreconciled `@`-suppressions, the empty `skeleton/` directory, module instantiation duplicated at 12 call sites) are done — see "Now" below.
* **Deliberately not built**: auth extras (remember-me, password reset, email verification, 2FA, OAuth/OIDC, WebAuthn — the two mail-dependent ones are blocked on a `mail` capability that doesn't exist yet), a `mail` capability itself, additional queue drivers (Redis/RabbitMQ), job batches/chains, payload encryption, ORM lock-for-update/composite keys/polymorphic relations, database-backed validation rules, an OpenTelemetry SDK adapter.
* **Never verified**: behaviour under a real production stack (php-fpm+nginx, FrankenPHP, RoadRunner — everything so far has run under `php -S` or PHP's built-in server); Firefox and Safari (the browser suite only drives Chrome); PHP patch levels other than 8.4.25 and 8.5.5; performance on hardware other than the one laptop everything was benchmarked on.

## Now: next 2-4 weeks — v0.1.3, then decide

Goal: close out everything already found, ship v0.1.3, and end the block with a clean slate before picking the next capability. Order matters less here than finishing the list.

1. **Land the three pending fixes** (already coded, sitting in the working tree): `make:request` hint, validation guide `default('')` correction, `RequestValidator` docblock.
2. ~~Correct the two stale doc lines~~ — done: verified with `gh run list` (six green jobs on the latest push) before correcting `docs/HARDENING_REPORT.md` and `docs/testing.md`.
3. ~~**R7**: scaffold `storage/` at `0750`~~ — done, matching what the file logger already created it as once it wrote (`ProjectScaffolder`'s base stub map and `CapabilityPublisher`'s per-capability directories, e.g. `logging`'s `storage/logs`, both fixed).
4. ~~**R9**: go through the `@`-suppressed call sites~~ — done: reviewed all 11, all are native calls whose return value is checked immediately; documented as the accepted exception to the zero-suppression rule in `docs/ARCHITECTURE_REVIEW_2.md`.
5. ~~Delete the empty `skeleton/` directory~~ — done: nothing had used it since the first commit, and its likely original purpose (project templates) is already served by `packages/console/resources/stubs/`.
6. ~~**R6**: a small `ModuleInstances` helper~~ — done. Traced all 12 call sites first: `ModuleManifest` already guarantees every class-string is a real `Module` before any of them run, so the actual gap was narrower than "missing validation" — a module whose *constructor* throws failed differently depending on which of the 12 sites hit it first. `src/Foundation/Manifest/ModuleInstances.php` now catches that once and names the module; 10 sites switched to it directly, `ModuleGraph` reuses it while keeping its own "collect every problem" shape (and gained a real fix along the way: it now also reports a class that exists but isn't a `Module`, which it silently let through before), and `CapabilityManager::positionFor()` was deliberately left alone (already guarded more cheaply, documented inline).
7. **Verify a real production deploy once**, at least minimally: php-fpm behind nginx (or FrankenPHP) serving a built project, confirming `HEAD` has no body, security headers are present, and the generic-error behaviour holds outside `php -S`. This has never been checked and every hardening claim so far only covers PHP's built-in server.
8. **Release v0.1.3**: same process as before — CI green on the release commit, tag, GitHub pre-release, confirm on Packagist, clean-room install check.

Everything in this block is fixing or verifying what already exists; nothing here is a new capability.

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
