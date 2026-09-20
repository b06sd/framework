# Configuration reference

Config files are `config/<name>.php` returning an array, or a closure taking `Runtime` (`$runtime->variable('NAME', 'default')`, `$runtime->secret('NAME')`, `$runtime->basePath`, `$runtime->environment`, `$runtime->debug`). `trunk package:install` publishes the file for a capability; existing files are never overwritten. **Values from `variable()` are compiled into `build/`** (rebuild after changing them); **`secret()` values never are**. Every value is validated by `trunk build`.

## `.env` settings

| Setting | Meaning |
| --- | --- |
| `APP_ENV` | `local` runs the development container. Unset or anything else is `production` (build required). |
| `APP_DEBUG` | `1` shows detailed error pages, only when `APP_ENV=local`. |
| `APP_NAME`, `APP_PORT` | Name shown in logs; `trunk serve` port (default 8006). |
| `DB_CONNECTION` `DB_HOST` `DB_PORT` `DB_DATABASE` `DB_USERNAME` `DB_PASSWORD` | Database (`sqlite`, `mysql`, `pgsql`). |
| `QUEUE_CONNECTION` | Which `database.connections` entry holds the queue tables. |
| `CACHE_DRIVER` `CACHE_PATH` `CACHE_PREFIX` | Cache (`file`, `array`, `null`). |
| `LOG_LEVEL` `LOG_CHANNEL` | Logging (`debug`..`emergency`; `stderr`, `file`, `null`). |
| `METRICS_TOKEN` | Enables `/metrics` (health capability). |

## `config/http.php`

| Key | Default | Meaning |
| --- | --- | --- |
| `max_body_bytes` | 2 MB | Larger bodies get 413 (checked by header and while streaming) |
| `max_files`, `max_file_bytes` | 20, 8 MB | Multipart upload limits |
| `max_json_depth` | 16 | Nesting `JsonBody` accepts |
| `max_uri_bytes` | 8192 | Longer request target gets 414 |
| `trusted_proxies` | `[]` | IPs/CIDRs whose `X-Forwarded-*` headers are believed |
| `security_headers` | enabled in new projects | `enabled`, `content_type_options`, `frame_options` (`DENY`/`SAMEORIGIN`), `referrer_policy`, `cross_origin_opener_policy`, `cross_origin_resource_policy`, `permissions_policy`, `content_security_policy` (each a string or `false`), `hsts` (seconds or `false`), `hsts_include_subdomains`, `hsts_preload` |

## `config/auth.php`

| Key | Default | Meaning |
| --- | --- | --- |
| `password.algorithm` | `argon2id` | or `bcrypt` (build fails if argon2id is missing from PHP) |
| `password.memory_cost` `time_cost` `threads` | 65536 KiB, 4, 1 | argon2id cost |
| `password.min_length` `max_length` | 12, 1024 | New passwords; anything over max is refused when checking |
| `users.table` `id` `identifier` `password` `session_version` | `users`, `id`, `email`, `password`, `session_version` | The built-in provider's table and columns |
| `session.store` | `database` | `database`, `file`, `array` |
| `session.table` `path` | `trunk_sessions`, `storage/sessions` | Database table / file directory |
| `session.cookie` | `session` | Name (a `__Host-` prefix is added when secure) |
| `session.idle_timeout` `lifetime` | 7200, 43200 s | Idle end / absolute end |
| `session.same_site` | `Lax` | `Lax`, `Strict`, `None` (needs secure) |
| `session.secure` | true in production | `Secure` + `__Host-` cookie |
| `tokens.table` `ttl` `touch_interval` | `trunk_tokens`, 30 days (0 = never), 300 s | API tokens; `last_used_at` is written at most this often |
| `throttle.table` | `trunk_auth_throttles` | Counter table |
| `throttle.max_attempts` `max_attempts_per_ip` `window` | 5, 30, 900 s | Failed-login limits |
| `throttle.max_new_sessions_per_ip` | 60 | Anonymous sessions per address per window |
| `login_path` | `/login` | Where `RequireLogin` sends browsers (a local path) |

## Other files

| File | Keys |
| --- | --- |
| `app.php` | `name` |
| `views.php` | `mode` (`development`/`compiled`), `paths`, `cache`, `build` |
| `database.php` | `default`, `log_queries`, `migrations`, `connections.*` (`driver`, `host`, `port`, `database`, `username`, `password`, `charset`/`sslmode`) |
| `orm.php` | `mode`, `maps` (discovered from `app/Orm/*Map.php`; the entities they name live in `app/Entities`), `build` |
| `queue.php` | `mode`, `jobs` (discovered from `app/Jobs`), `connection`, `table`, `failed_table`, `max_payload` (64 KB), `visibility_timeout` (600), `store_failure_messages`, `worker.*` (`memory_limit` 256M, `growth_warn` 64M, `max_jobs` 1000, `max_runtime` 3600, `gc_interval` 100, `sleep` 3) |
| `validation.php` | `mode`, `requests` (discovered from `app/Requests/*.php`), `build` |
| `cache.php` | `driver`, `path`, `prefix` |
| `logging.php` | `level`, `channel`, `format` (`json`/`line`), `path`, `service`, `redact` (extra keys to mask) |
| `errors.php` | `format` (`auto`, `json`, `html`, `text`) |
| `observability.php` | `metrics` (true), `tracing` (false) |
| `health.php` | `metrics_token` (from `secret('METRICS_TOKEN')`), `debug` |

Secrets in log context (`password`, `token`, `authorization`, `cookie`, `api_key`, ... at any depth, plus `Bearer …` and `password=…` inside text) are always redacted; control characters and line breaks are escaped so log lines cannot be forged.
