# Testing

Three different things: testing **your application**, running **the framework's own test suites**, and **trying to break it** yourself.

## 1. Test your application

A project comes with PHPUnit configured (`trunk test`, or `vendor/bin/phpunit`). Test services and jobs as plain classes (constructor injection makes that easy). For HTTP, `Trunk\Testing\TestApp` (bundled with `trunkphp/framework`, nothing extra to install) gives you a real test client: it boots your actual application (`trunk.php`, `config/*.php`) and drives its kernel with cookies, JSON and fluent assertions.

```php
final class HomeTest extends TestCase
{
    public function test_the_home_page_renders(): void
    {
        TestApp::client(dirname(__DIR__))->get('/')->assertOk()->assertSee('<h1>Demo</h1>');
    }

    public function test_unknown_paths_are_a_json_404(): void
    {
        TestApp::client(dirname(__DIR__))->get('/nope')->assertStatus(404)->assertHeader('X-Request-Id');
    }
}
```

`TestApp::client($root)` is `Trunk\Testing\TestApp` (`$root` is the directory holding `trunk.php`, typically `dirname(__DIR__)`). It returns a `Trunk\Testing\TestClient`: `get`, `post`, `put`, `patch`, `delete` (form bodies) and `json($method, $uri, $data)` (a JSON body), each returning a `Trunk\Testing\TestResponse` with `assertStatus`, `assertOk`, `assertRedirect`, `assertHeader`, `assertHeaderMissing`, `assertSee`, `assertDontSee`, `assertJson` (a subset of the top-level keys), `json()` and `status()`. `withHeader()` and `withAddress()` set something on every request the client makes from then on (an `Authorization` header, or a specific `client_ip` for testing rate limits and login throttling). Cookies are kept and sent back automatically, so a login followed by a request to a page behind it needs no extra wiring:

```php
$client = TestApp::client(dirname(__DIR__));
$csrf = $client->get('/login')->csrfToken();                 // reads the real <input name="_csrf"> from the page
$client->post('/login', ['_csrf' => $csrf, 'email' => 'ada@example.com', 'password' => 'correct horse battery'])
    ->assertRedirect('/account');
$client->get('/account')->assertOk()->assertSee('ada@example.com');   // the session cookie carried over
```

`csrfToken(string $field = '_csrf')` reads the value the same way a browser would: from the page it just fetched, before submitting a form (an HTML `<input>` of that name, in any attribute order, or that key in a JSON body). `TestClient` sends `Accept: application/json`, so a route guarded by `RequireLogin` answers a signed-out request with `401`, not the redirect it gives an actual browser — assert `401` there, not a redirect.

To exercise a database, pass `variables: ['DB_DATABASE' => $tempFile]` as `TestApp::client()`'s third argument and run your migrations against it first. To test the compiled build, run `trunk build`, then `TestApp::client($root, 'production')`.

Prefer the raw kernel yourself, or a PSR-7 request that is not `Trunk\Http\Message\ServerRequest`? `TestApp::client()` is a thin wrapper around exactly this:

```php
$project = new ProjectLoader()->load($root);
$factory = new ApplicationFactory();
$runtime = $factory->runtime($project, ['APP_ENV' => 'local', 'APP_DEBUG' => '0']);
$kernel = new HttpKernelFactory()->development($factory->create($project, $runtime), new ModuleManifest($project->modules));
$response = $kernel->handle($request);   // any PSR-7 ServerRequestInterface
```

## 2. Run the framework's own suites

Inside a checkout of this repository (`composer install` first):

```bash
composer quality          # format check, PHPStan (max level, no suppressions), and the whole test suite
composer test             # every suite except Install
composer test:install     # real `composer install` of every project type, ~35 s, needs network
composer test:browser     # real Chrome against php -S; skips with a message if node or Chrome is missing
vendor/bin/phpunit --testsuite Security               # one suite: Unit Integration Security Architecture Performance Install
vendor/bin/phpunit --filter test_a_head_request       # one test
```

| Suite | What it covers |
| --- | --- |
| `Unit`, `Integration` | Each package's behaviour, through its own classes and through the real kernel in both the development and the compiled container |
| `Security` | Adversarial inputs: injection payloads, header/cookie injection, hostile `$_SERVER`, fuzzed filters, session and CSRF attacks, no secrets in logs, forbidden constructs (`eval`, `unserialize`, weak hashes) |
| `Architecture` | Layering (core imports no package, no cycles), manifests match imports, minimal core `require`, the public API rules and that `docs/API.md` is current |
| `Performance` | Benchmarks (numbers printed to stderr, loose assertions) |
| `Install` | The path a new user takes: `trunk new`, `composer install`, `doctor`, `build`, real requests, for api/web/self-contained/cli/worker, plus enabling `auth` and issuing a token |

**Live MySQL and PostgreSQL.** The database, ORM, queue and auth suites also run against real servers when these variables are set (SQLite always runs). They create and drop only tables named `trunk_*`:

```bash
TRUNK_TEST_MYSQL_HOST=127.0.0.1 TRUNK_TEST_MYSQL_DATABASE=<db> TRUNK_TEST_MYSQL_USER=root \
TRUNK_TEST_PGSQL_HOST=localhost TRUNK_TEST_PGSQL_DATABASE=<db> TRUNK_TEST_PGSQL_USER=postgres TRUNK_TEST_PGSQL_PASSWORD=<pw> \
vendor/bin/phpunit
```

(Also `..._PASSWORD` and `..._PORT` for MySQL.) Without them 26 tests skip; with them, the reference run is 1841 tests, 0 skipped.

**Another PHP version:** put its `bin` first on `PATH` (`PATH=/opt/homebrew/opt/php@8.4/bin:$PATH vendor/bin/phpunit`). The suite passes on 8.4.25 and 8.5.5. **Lowest dependencies:** `composer update --prefer-lowest --prefer-stable` in a copy, then `composer test`.

**Benchmarks:**

```bash
vendor/bin/phpunit tests/Performance/BootstrapBenchmark.php     # bootstrap, request, route match, memory growth, cold process, sqlite/ORM
vendor/bin/phpunit tests/Performance/AuthPerformanceTest.php    # argon2id by parameters, login, session/token/CSRF/gate paths
vendor/bin/phpunit tests/Performance/HardeningBenchmark.php     # http, mvc, orm paths
```

## 3. Try to break it

With a project running under `trunk serve` (port 8006), and the results the framework is expected to give (all of these were run):

```bash
# body too large -> 413        head -c 3000000 /dev/zero | curl -s -o /dev/null -w '%{http_code}\n' -X POST localhost:8006/api/posts -H 'Content-Type: application/json' --data-binary @-
# request target too long -> 414
curl -s -o /dev/null -w '%{http_code}\n' "localhost:8006/$(head -c 9000 /dev/zero | tr '\0' a)"
# wrong method -> 405 with Allow: GET, HEAD
curl -si -X DELETE localhost:8006/posts | head -3
# HEAD -> headers only, 0 bytes of body
curl -s --head localhost:8006/posts -o /dev/null -w '%{size_download}\n'
# forwarded headers are ignored unless the peer is a trusted proxy: still plain http, real host
curl -s -o /dev/null -w '%{http_code}\n' -H 'X-Forwarded-Proto: https' -H 'X-Forwarded-Host: evil.example' localhost:8006/posts
# security headers present on pages and error pages
curl -sI localhost:8006/nope | grep -iE 'x-frame|x-content|referrer|content-security'
# not JSON -> 415 ; empty title -> 422 ; unknown id -> 404
curl -s -X POST localhost:8006/api/posts -d x=1
# CSRF: a post without the token is 403
curl -s -o /dev/null -w '%{http_code}\n' -X POST localhost:8006/logout
# throttling: five wrong passwords, the sixth (even with the right one) is 429
# (fetch a fresh /login token with a cookie jar each time, then POST /login with email+password+_csrf)
# escaping: create a post whose body is <script>alert(1)</script>, open /posts/<id>, view source: &lt;script&gt;
```

Deeper attacks are already automated: `vendor/bin/phpunit --testsuite Security`, and the browser suite covers cross-site forms, framing, session fixation, idle timeout, lockout and bearer tokens in a real browser. Reports of what was tried, found and fixed are in [HARDENING_REPORT.md](HARDENING_REPORT.md).

## What is not tested

Browsers other than Chrome; a production web server (php-fpm with nginx, FrankenPHP, RoadRunner) in place of PHP's built-in server; realistic large applications; comparisons with other frameworks.
