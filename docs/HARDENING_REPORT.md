# Hardening report: http, mvc, orm (and the database layer under it)

Method: adversarial and fuzz tests first, measurements second; code changed only where a failing test or a measurement justified it, then re-measured. No public API or documented behaviour was changed except where a finding says so. Environment: macOS, PHP 8.5.5 (and 8.4.25 for the correctness runs), SQLite in memory for micro-benchmarks, MySQL and PostgreSQL local servers for the live tests. Timings are medians on one laptop; they are useful for relative comparison, not as absolute promises.

## What was found and fixed

| # | Package | Finding | Evidence | Fix | Behaviour change |
| --- | --- | --- | --- | --- | --- |
| 1 | http | A response to `HEAD` was sent **with a body**. | Reading the emitter (it never knew the method) and a kernel test on both containers. | `HttpKernel::send()` emits without the body for `HEAD`; `ResponseEmitter::emit()` takes an optional `$withBody`. | Yes, deliberate: RFC 9110. |
| 2 | http | No limit on the request target: a 100 KB path was accepted and fed to the router (200 to 360 µs per request, linear, not exploitable, but unbounded). | Router probe with 100 KB paths and a test. | `max_uri_bytes` (default 8192, range 256 to 65536): longer is a 414 before anything is parsed. | Only for targets over 8 KB, which web servers already refuse. |
| 3 | http | `Host: a.test:0` was accepted. | Probe and test. | Port 0 in a Host header is a malformed host (400). | Rejects a nonsense value. |
| 4 | orm | A wrong-typed value for an **enum filter** (`?status=1`) raised a `TypeError`, i.e. a **500 from a query string**. | Random request-shaped filter fuzz. | Match the value against the backing type; an int-backed enum accepts a whole-number string (which is what a query string is); anything else is `InvalidFilter`. | 500 becomes a clean rejection. |
| 5 | orm | `paginate(PHP_INT_MAX)` overflowed the offset into a float and raised a `TypeError` (a 500 from `?page=`). | Extreme-paging test. | `InvalidFilter('page is too large.')` when the offset would overflow. | 500 becomes a clean rejection. |
| 6 | orm / database (PostgreSQL) | An integer filter outside the column's range, or text that is invalid UTF-8, raised a `QueryException` (a 500 from a query string). | Live-database fuzz: 2 failing input shapes on PostgreSQL, none on MySQL or SQLite. | A database data-exception (SQLSTATE 22xxx) on a query that has conditions is `InvalidFilter`, without the database's message; filter values with invalid UTF-8 are rejected on PostgreSQL. | 500 becomes a clean rejection. |
| 7 | orm (PostgreSQL) | **PostgreSQL silently cuts text at a NUL byte**, on filters (`label = 'item 1\0zzz'` matched `item 1`) and on writes (the stored value lost everything after the NUL). MySQL keeps the bytes; SQLite keeps them. | Direct probe on all three servers. | On PostgreSQL only: a NUL in a filter value is `InvalidFilter`; a NUL in a value being saved throws `OrmException` instead of corrupting the data. MySQL and SQLite behave as before (the existing tests that store NUL through the ORM still pass). | Yes, PostgreSQL only, and only for input that was being corrupted. |
| 8 | database | `Driver::grammar()` created a **new grammar object for every query**, which made every query pay again for work the grammar could remember. | Micro-profile: building and compiling one ORM query. | The connection keeps one grammar (it is stateless apart from a bounded memo of validated, wrapped identifiers). | None; output identical (differential test). |
| 9 | logging | `RequestId::generate()` cost 2.75 µs per request, about 19% of a 14.5 µs request, because it built a string of "0" and "1" characters. | Micro-profile of the request path. | A small integer accumulator; the id is bit-for-bit the same (tested against the old definition on 500 random inputs). | None. |
| 10 | auth (found by the real-browser test) | Private pages could be **restored by the back button after logout**: no `Cache-Control`. | Chrome 153 test. | Responses to a signed-in user, and the logout response, get `Cache-Control: no-store` unless the handler set its own. | Adds a header. |

Also added, opt-in: `SecurityHeaders` / `SecurityHeadersMiddleware` (see below).

## Measured before and after

| Measurement (p50) | Before | After |
| --- | --- | --- |
| Warm HTTP request, compiled container | 14.5 µs | **12.8 µs** (-12%) |
| Query builder `first()` (SQLite) | 5.8 µs | **4.6 µs** (-21%) |
| ORM `readOnly()` query `first()` | 18.5 µs | **13.6 µs** (-26%) |
| ORM `find()` with a new manager | 22.0 µs | **16.6 µs** (-25%) |
| One 12-column builder select + fetch | 13.1 µs | **7.7 µs** (-41%) |
| Security headers, 8 headers per response | 4.5 µs (naive `withHeader` x8) | **0.8 µs** (one copy of the response) |

Measured and found fine, so unchanged: ORM hydrate 1.4 µs/row (generated), 2.6 µs/row (interpreted, development); dirty check 2.2 µs/entity; insert + flush 9.9 µs/row (batched inserts already); eager loading 3.6 µs/row; `cursor()` streaming 3.8 µs/row with flat memory (20,000 rows under 4 MB extra); identity map growth is bounded by `clear()` (a worker that clears between units of work uses under 2 MB for 12,000 rows, one that does not grows about 3x or more); a 64 MB response body is emitted in 8 KB chunks with under 8 MB of extra memory; router: 0.4 µs static, 3.1 µs dynamic, 2.0 µs miss over 1,000 routes; MVC view render (Tusk, layout, compiled or cached) about 14.7 µs for a whole request; request creation from 14 browser headers 10 µs.

Paging and request-style filtering take 300 and 170 µs on a 2,000-row unindexed SQLite table; that is the database sorting and scanning, not the framework.

In a single (unrepeated) run on PHP 8.4.25 the ORM and queue micro-benchmarks came out roughly 30 to 50% slower than on 8.5.5 on this machine (for example flush 12.7 versus 9.4 µs/row); treat that as indicative only. Behaviour is identical.

## What was tested and held (no change needed)

* http: whatever is in `$_SERVER` (600 random arrays of wrong-typed, oversized and hostile values, including forwarded headers from a trusted proxy) only ever yields typed rejections; 3,000 random URL-like strings never make `Uri` report a different host than PHP's reference parser or a host containing `@`; 700 random paths and methods against both containers never produce anything but 200, 404, 405 (and the deliberate 500 route), each with a request id; header and cookie injection through every path; hostile Host values; error pages escape everything and use `ENT_SUBSTITUTE`; the JSON error renderer substitutes invalid UTF-8.
* mvc: a broken view in production is a generic 500 with no template name, path, source or data (both container modes); user input is escaped in text and quoted attributes; redirects built from input cannot leave the site or split headers.
* orm: 800 random request-shaped filters and sorts on SQLite, and 400 hostile filter values per server on MySQL and PostgreSQL, never change data and only produce rows or typed rejections; a failed flush is all-or-nothing and the error does not echo row values; hidden columns, mass assignment, scopes and identifier handling were already covered by existing tests and still pass.

## New in http (opt-in)

`Trunk\Http\Security\SecurityHeaders` and `SecurityHeadersMiddleware`: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, cross-origin opener and resource policy, `Permissions-Policy`, a safe-subset `Content-Security-Policy`, and `Strict-Transport-Security` only on https requests. Configured under `security_headers` in `config/http.php`, validated at build, never overwriting a header a handler set, applied to every response (error pages included) when `enabled`, and confirmed in Chrome (framing blocked, headers on the 404 page).

## What could not be verified

* GitHub Actions: the workflow was validated structurally and each job's commands were run locally on PHP 8.4 and 8.5, but it has **never run on GitHub**.
* Browsers other than Chrome 153 (Firefox, Safari); other PHP patch levels.
* Behaviour under a production web server (php-fpm with nginx, FrankenPHP, RoadRunner) rather than PHP's built-in server; the HEAD fix in particular matters most where the SAPI does not strip the body itself.
* Absolute performance on other hardware, and realistic large applications; no comparison with other frameworks was made.
* The PostgreSQL NUL and range findings were reproduced on the local PostgreSQL 18.3 server only (MySQL 9.6.0 for the comparisons).
