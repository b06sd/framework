# Changelog

Notable changes per release. Public API changes are described in `UPGRADE.md`.

## Unreleased

* Fixed: `make:request` printed its usage hint with stray backslashes (`\$requests`).
* Documentation: the validation guide's form template now reads errors and old input with `|default('')`, so it renders on the first visit (Tusk treats a missing key as an error, on purpose; the Tusk guide now says so); the guide also documents `#[From(Source::Json)]` for JSON-only endpoints (`415` for other content types).
* Added: `trunk doctor` now boots the real application and checks that a capability's own tables actually exist (`auth`, `queue`), catching a missing `queue:table`/`auth:table` migration before it becomes a 500. Capabilities can add their own check by implementing the new `Trunk\Doctor\DoctorCheck` and tagging it `trunk.doctor_check`.
* Fixed: `packages/queue`'s own `composer.json` listed `logging` as a required capability where it should have said `diagnostics` (matching what the queue module actually needs); `src/Foundation/Capability/BuiltInCapabilities.php` already had it right.
* Added: a `redis` cache driver (`CACHE_DRIVER=redis`, needs `composer require predis/predis`) — the only store that stays correct once an application runs on more than one server; `file` and `array` are local to one box. A project whose `config/cache.php` predates this change (no `redis` block) is unaffected.
* Added: CORS support (`cors` in `config/http.php`, off by default). `Trunk\Http\Security\Cors` and `Trunk\Http\Middleware\CorsMiddleware` answer a preflight before routing runs (the router cannot otherwise match `OPTIONS` on a route only ever declared for `GET`/etc.) and add `Access-Control-Allow-Origin` and friends to real cross-origin responses, error pages included. A project whose `config/http.php` predates this change is unaffected (`cors.enabled` defaults to false).
* Added: the `rate-limit` capability (`trunkphp/ratelimit`, `Trunk\RateLimit`): a database-backed `RateLimiter` (correct across requests, processes and servers) and a ready-made `RateLimitMiddleware` any route or group can opt into. `trunk package:install rate-limit`, then `trunk rate-limit:table && trunk migrate`. See `docs/rate-limiting.md`.

## 0.1.2

* Added: the `validation` capability: attribute rules on request classes, `Validator`, `RequestValidator`, `make:request`, compiled plans in `build/validation.php`, and the guide `docs/validation.md`.
* Changed: `trunk make:entity` writes the entity to `app/Entities/` (`App\Entities`) and its map to `app/Orm/`, and `package:install orm` creates both folders. Maps are found exactly as before, so existing projects need no change.
* Documentation: the ORM guide explains why there is no `Model` class ("Coming from Laravel or another framework?").
* Fixed: an ORM filter or sort taken from a request that the entity map does not allow (`InvalidFilter`, `UnknownProperty`) is now a `400` with a generic message, as the docs promised; it was a 500. `UnknownProperty` from application code stays a 500.
* Fixed: the `AppModule` written by `trunk new` passes PHPStan at the maximum level (route files load through the new `RouteFile::load()`).
* Fixed: a command that cannot be built no longer breaks the console: `trunk list` and unknown commands still work and name the broken command; other commands are unaffected.
* Fixed: `trunk --version` and the banner show the installed Composer version instead of a hard-coded `0.1.0`.
* Fixed: an empty `/metrics` export is a comment line explaining that metrics are per process; the deployment docs no longer suggest php-fpm accumulates them.
* Fixed: the troubleshooting page shows the full route-group prefix message.

## 0.1.1

* Added: new web projects start with a designed layout, home page and error pages (light and dark, responsive, no icons), `public/styles.css` and `public/script.js`; `trunk serve` now serves real static files from `public/`.
* Changed: the Composer vendor is `trunkphp` (`trunkphp/framework`, ...); `trunk new` without `--repository` requires `trunkphp/framework ^0.1` at stable stability.
* Fixed: `psr/http-factory` requires `^1.1` (1.0.0 triggers deprecations on PHP 8.4).
* Documentation: full guides in `docs/` and Packagist install instructions.

## 0.1.0

First public pre-release. Contents:

* Added: the `auth` package (password hashing, sessions, CSRF, bearer tokens, login throttling, policies), `Trunk\Contracts\Clock`, capability composer requirements, real-install test suite (`composer test:install`), `diagnostics` capability, `observability` package, `http.trusted_proxies`, route and group middleware, request limits, module dependencies, `secret()` for build-safe secrets.
* Hardened (http, mvc, orm, database): `HEAD` responses carry no body, a request target limit, Host header port 0 refused, ORM enum and page-number inputs can no longer cause a server error, PostgreSQL NUL and out-of-range handling, one shared query grammar per connection (about 25% faster ORM reads), a cheaper request id (about 12% faster requests). See `docs/HARDENING_REPORT.md`.
* Added: `SecurityHeaders` and `SecurityHeadersMiddleware`, a real-browser test suite (`composer test:browser`), tokens tied to the owner's session version, an anonymous-session creation limit.
* Changed: public API is defined by `@api` tags and documented in `docs/API.md`.
