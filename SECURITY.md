# Security policy

Trunk is pre-release (0.x). Security reports are welcome and taken seriously.

## Reporting a vulnerability

**Please do not open a public issue.** Use GitHub's private reporting: on the repository's **Security** tab choose **Report a vulnerability**. Include what you found, how to reproduce it, the version or commit, and the impact you expect. You will get an acknowledgement, and a fix or a decision on the report as soon as it can be made.

## What is in scope

The framework code in `src/` and `packages/*` (http, router, tusk, mvc, console, cache, database, orm, queue, auth, observability), the `trunk` command line, generated project templates, and the documentation when it advises something unsafe.

Especially interesting: authentication or session bypass, CSRF bypass, request smuggling or header injection, SQL injection through the query builder or ORM, template escaping bypass, secrets appearing in `build/`, logs or error responses, and any way to make production show internals.

## Supported versions

Until 1.0 only the latest 0.x release receives fixes. Public API changes between 0.x releases are listed in `UPGRADE.md`.

## What the project already does

See `docs/HARDENING_REPORT.md` and `docs/testing.md` for what has been attacked and how to try it yourself (`vendor/bin/phpunit --testsuite Security`, `composer test:browser`).
