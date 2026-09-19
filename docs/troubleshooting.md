# Troubleshooting

Run `trunk doctor` first; it names most of these.

| Symptom | Cause and fix |
| --- | --- |
| Production says a build is required | `APP_ENV` is unset or not `local`, which means production. Run `trunk build`, or set `APP_ENV=local` while developing. |
| A page shows generic "An unexpected error occurred" | That is production behaviour. Use the request id to find the log line; in development set `APP_ENV=local` and `APP_DEBUG=1`. |
| `Interface "Psr\Http\..." not found` (or a similar missing class) right after install | A capability's Composer packages are missing. `trunk doctor` shows the `composer require` line; `trunk package:install <id>` installs them for you. |
| `There is no command "migrate"` (or `queue:work`, `auth:token`...) | The command belongs to a package whose console integration needs the `console` capability: `trunk package:install console`. |
| `Group prefix "" must start with "/"` | Route groups need a prefix. For root-level routes give middleware per route: `middleware: [...]`. |
| Build error `Scoped service ... was requested by a singleton` | A singleton depends on a request-scoped service. Make the consumer scoped (`$builder->scoped(...)`) or inject a factory. Controllers and middleware are already scoped. |
| `service(X::class, X::class, [])` does not inject anything | An explicit empty argument list disables autowiring. Use `$builder->autowire(X::class)` or `scoped(X::class)`. |
| `Route ... parameter $x cannot be bound` at build | The handler parameter is not the request, a route parameter, or defaulted. `trunk route:list` shows how each is bound. |
| Change to `.env` or config has no effect in production | Values are compiled. Rebuild. |
| Login works but you are signed out on the next request over http | Cookies are `Secure` in production. For plain http set `auth.session.secure` to `false`; use https otherwise. |
| A POST returns 403 `CSRF_TOKEN_INVALID` | Missing or stale `_csrf` field / `X-CSRF-Token` header; the form page must call `Csrf::token()`; a cross-site `Origin` is always refused. |
| 429 `TOO_MANY_REQUESTS` on login | Five failures per account+address (or thirty per address) in 15 minutes; `Retry-After` says how long. Anonymous visitors are also limited (`max_new_sessions_per_ip`). Clear with `trunk auth:prune` after the window, or raise the limits in `config/auth.php`. |
| 413 / 414 / 415 | Body over `max_body_bytes`; request target over `max_uri_bytes`; a JSON endpoint received another content type. |
| `Refusing to update every row` | `update()`/`delete()` without `where()`; add a condition or call `unrestricted()` on purpose. |
| ORM `InvalidFilter` for a request filter | The property is not `filterable()`, the value has the wrong type, or too many values; that is the intended 4xx. |
| Queue job fails with `TransactionLeftOpen` | The job returned inside an open transaction; commit or roll back before returning. |
| `trunk queue:work` exits by itself | It hit a memory, job-count or runtime limit (by design). Run it under a supervisor. |
| A `php -S` server keeps running after tests | `php -S` forks workers; stop the whole process group, not just the parent. |
