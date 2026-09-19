# Changelog

Notable changes per release. Public API changes are described in `UPGRADE.md`.

## Unreleased

* Added: the `auth` package (password hashing, sessions, CSRF, bearer tokens, login throttling, policies), `Trunk\Contracts\Clock`, capability composer requirements, real-install test suite (`composer test:install`), `diagnostics` capability, `observability` package, `http.trusted_proxies`, route and group middleware, request limits, module dependencies, `secret()` for build-safe secrets.
* Hardened (http, mvc, orm, database): `HEAD` responses carry no body, a request target limit, Host header port 0 refused, ORM enum and page-number inputs can no longer cause a server error, PostgreSQL NUL and out-of-range handling, one shared query grammar per connection (about 25% faster ORM reads), a cheaper request id (about 12% faster requests). See `docs/HARDENING_REPORT.md`.
* Added: `SecurityHeaders` and `SecurityHeadersMiddleware`, a real-browser test suite (`composer test:browser`), tokens tied to the owner's session version, an anonymous-session creation limit.
* Changed: public API is defined by `@api` tags and documented in `docs/API.md`.
