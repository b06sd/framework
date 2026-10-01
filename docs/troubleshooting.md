# Troubleshooting

Run `trunk doctor` first; it names most of these.

| Symptom | Cause and fix |
| --- | --- |
| Production says a build is required | `APP_ENV` is unset or not `local`, which means production. Run `trunk build`, or set `APP_ENV=local` while developing. |
| A page shows generic "An unexpected error occurred" | That is production behaviour. Use the request id to find the log line; in development set `APP_ENV=local` and `APP_DEBUG=1`. |
| `Interface "Psr\Http\..." not found` (or a similar missing class) right after install | A capability's Composer packages are missing. `trunk doctor` shows the `composer require` line; `trunk package:install <id>` installs them for you. |
| A 500 the first time you dispatch a job or sign someone in, right after `package:install queue`/`auth` | The capability's own table was never created. `trunk doctor` boots the real application and checks for it; run its suggested fix (`trunk queue:table && trunk migrate`, or `trunk auth:table && trunk migrate`). |
| A cached value looks stale, or missing, depending on which server answers the request | `CACHE_DRIVER=file` (the default) and `array` are local to one machine: with more than one web server each has its own cache. Set `CACHE_DRIVER=redis` (needs `composer require predis/predis`) so every server shares the same cache. |
| `There is no command "migrate"` (or `queue:work`, `auth:token`...) | The command belongs to a package whose console integration needs the `console` capability: `trunk package:install console`. |
| `There is no command "x".` followed by `Note: App\Commands\Y could not be loaded: ...` | Not a typo: a command your modules contribute could not be built (usually a service it needs is no longer registered, for example after `package:remove`). The note says which command and why; every other command keeps working. Fix the dependency or unregister the command. |
| `Group prefix "" must start with "/" and must not end with "/".` | Route groups need a non-empty prefix such as `/api`. For root-level routes give middleware per route: `middleware: [...]`. |
| Build error `Scoped service ... was requested by a singleton` | A singleton depends on a request-scoped service. Make the consumer scoped (`$builder->scoped(...)`) or inject a factory. Controllers and middleware are already scoped. |
| `service(X::class, X::class, [])` does not inject anything | An explicit empty argument list disables autowiring. Use `$builder->autowire(X::class)` or `scoped(X::class)`. |
| `Route ... parameter $x cannot be bound` at build | The handler parameter is not the request, a route parameter, or defaulted. `trunk route:list` shows how each is bound. |
| Change to `.env` or config has no effect in production | Values are compiled. Rebuild. |
| Login works but you are signed out on the next request over http | Cookies are `Secure` in production. For plain http set `auth.session.secure` to `false`; use https otherwise. |
| A POST returns 403 `CSRF_TOKEN_INVALID` | Missing or stale `_csrf` field / `X-CSRF-Token` header; the form page must call `Csrf::token()`; a cross-site `Origin` is always refused. |
| 429 `TOO_MANY_REQUESTS` on login | Five failures per account+address (or thirty per address) in 15 minutes; `Retry-After` says how long. Anonymous visitors are also limited (`max_new_sessions_per_ip`). Clear with `trunk auth:prune` after the window, or raise the limits in `config/auth.php`. |
| 413 / 414 / 415 | Body over `max_body_bytes`; request target over `max_uri_bytes`; a JSON endpoint received another content type. |
| `Refusing to update every row` | `update()`/`delete()` without `where()`; add a condition or call `unrestricted()` on purpose. |
| ORM `InvalidFilter` / `UnknownProperty` for a request filter or sort | The property is not `filterable()`/`sortable()`, the value has the wrong type, or there are too many values. The client gets `400 BAD_REQUEST` ("The filter or sort is not valid."); the log line has the detail. An `UnknownProperty` from your own code (`with('typo')`) is a 500: fix the code. |
| Queue job fails with `TransactionLeftOpen` | The job returned inside an open transaction; commit or roll back before returning. |
| `trunk queue:work` exits by itself | It hit a memory, job-count or runtime limit (by design). Run it under a supervisor. |
| A `php -S` server keeps running after tests | `php -S` forks workers; stop the whole process group, not just the parent. |
| `Class "Faker\Factory" not found` after `trunk make:factory` | Faker isn't installed: `composer require --dev fakerphp/faker`. |
| `db:seed` says `database/seeders/DatabaseSeeder.php does not exist` | Create it: `return static function (EntityManager $manager): void { ... };`. |
| `schedule:run` says a scheduled command was skipped, `vendor/bin/trunk does not exist` | Run `composer install`: `schedule:run` runs a command through the same binary a real deploy uses, so it needs to exist. |
| `schedule:run` says "Another schedule:run is still running; this pass was skipped." | A previous pass (usually a slow scheduled command) had not finished when cron fired again; harmless, it will catch up next minute. If it happens every minute, the command is taking longer than a minute and should not be scheduled every minute. |
| `trunk pipeline:run` starts a run but nothing ever happens | A pipeline's chunks run on the `pipelines` queue, not `default`. Run `trunk queue:work --queue=pipelines` (or `--queue=default,pipelines` to share one worker). |
| A pipeline run is stuck `running` in `trunk pipeline:status` with no job in `trunk queue:failed` | The chunk that would continue it was lost rather than failed (rare — a crash between one chunk succeeding and the next being dispatched). `trunk pipeline:resume <run-id>` re-dispatches it from the last recorded cursor. |
