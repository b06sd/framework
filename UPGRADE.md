# Upgrade notes

Public API changes are listed here (see `docs/API.md` for what counts as public). Newest first.

## Unreleased (0.1.x development)

* New public type `Trunk\Contracts\Clock` replaces `Trunk\Cache\Clock\Clock` and `Trunk\Queue\Worker\Clock` (both were internal). Code that bound its own clock for the cache or the queue should bind `Trunk\Contracts\Clock` instead; `Trunk\Support\SystemClock` is the default.
* **`trunk_tokens` has a new `user_version` column** and `AccessToken` a new `userVersion` property: a token now ends when its owner's `session_version` changes (a password change), exactly like a session. Regenerate the migration with `trunk auth:table` for a new project; a project that already ran the earlier migration needs `ALTER TABLE trunk_tokens ADD user_version VARCHAR(64) NOT NULL DEFAULT ''` and its old tokens re-issued (they no longer match).
* New auth setting `auth.throttle.max_new_sessions_per_ip` (default 60 per throttle window): one address can start only that many anonymous sessions; beyond it the request is a 429.
* New http setting `security_headers` in `config/http.php` and new types `Trunk\Http\Security\SecurityHeaders` and `Trunk\Http\Middleware\SecurityHeadersMiddleware`. Nothing changes for an existing project until it adds `'security_headers' => ['enabled' => true]`; `trunk new` projects have it on.
* New http setting `max_uri_bytes` (default 8192): a longer request target is a 414. `RequestLimits` has a new `maxUriBytes` argument (last).
* A response to a `HEAD` request no longer carries a body when sent through `HttpKernel::send()` / `run()` (RFC 9110). `ResponseEmitter::emit()` has a new optional `$withBody` argument.
* ORM: on PostgreSQL a NUL byte in a text value being saved is refused (it used to be silently cut off there), and request filters containing a NUL byte or invalid UTF-8 are rejected as `InvalidFilter` on PostgreSQL. An integer or text value that a database rejects as out of range in a filter is now `InvalidFilter` instead of a `QueryException`.
* New capability **`auth`** (`packages/auth`, `Trunk\Auth`): see `docs/API.md` for its public types. Enable it with `trunk package:install auth`.

* The `logging` capability now provides only the PSR-3 logger. The error pipeline, lifecycle reset and health checker moved to the new **`diagnostics`** capability (`Trunk\Foundation\Diagnostics\DiagnosticsModule`, which requires `logging`; `errors.php` moved with it). Projects created by `trunk new` already list both; for an existing project run `trunk package:install diagnostics`. The `health` and `queue` capabilities now require `diagnostics`.
* `Trunk\Foundation\Logging\LoggingModule` keeps its name; the classes it used to bind for errors and health are now bound by `DiagnosticsModule`.
