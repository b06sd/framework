# Trunk documentation

Trunk is a PHP 8.4+ framework built around three ideas: **explicit** (constructor injection, no facades, no global helpers, no base classes), **compiled** (`trunk build` validates and precompiles the container, routes, views, ORM maps and job codecs, and production runs only that build), and **safe by default** (generic errors in production, strict input limits, secrets kept out of the build, layered defences in the auth and http packages).

Every example in these guides was run in a real project created with `trunk new` and installed with `composer install`, in development and in the compiled production mode.

## Start here

| If you want to... | Read |
| --- | --- |
| Install it and see a page in five minutes, then build a small real app | [Getting started](getting-started.md) |
| Understand modules, capabilities, the container and the build | [Concepts](concepts.md) |
| Look up a command | [Command line reference](cli.md) |
| Look up a setting | [Configuration reference](configuration.md) |
| Try to break it, or run its tests | [Testing](testing.md) |

## Guides

* [HTTP: routing, controllers, middleware, errors, limits, security headers](http.md)
* [Views: Tusk templates and the Responder](views.md)
* [Database: connections, query builder, schema, migrations](database.md)
* [ORM: entities, maps, repositories, the unit of work](orm.md)
* [Queue: jobs and workers](queue.md)
* [Auth: passwords, sessions, CSRF, tokens, throttling, policies](auth.md)
* [Deployment](deployment.md)
* [Troubleshooting](troubleshooting.md)

## Reference and background

* [Public API and compatibility policy](API.md) (the list of supported types)
* [Hardening report](HARDENING_REPORT.md) (what was attacked, found and fixed in http, mvc and orm, with measurements)
* [Architecture review](ARCHITECTURE_REVIEW.md) and [second pass](ARCHITECTURE_REVIEW_2.md)
* [UPGRADE](../UPGRADE.md) and [CHANGELOG](../CHANGELOG.md)

## Status

Version 0.1 (pre-release). Until 1.0 a minor release may change public API; every change is listed in `UPGRADE.md`. Not built yet: remember-me cookies, password reset and email verification, two-factor, OAuth/OIDC, WebAuthn, session stores other than database, file and array.
