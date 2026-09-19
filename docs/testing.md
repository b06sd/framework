# Testing

Three different things: testing **your application**, running **the framework's own test suites**, and **trying to break it** yourself.

## 1. Test your application

A project comes with PHPUnit configured (`trunk test`, or `vendor/bin/phpunit`). Test services and jobs as plain classes (constructor injection makes that easy), and test HTTP behaviour through the real kernel:

```php
final class HttpTest extends TestCase
{
    private static function kernel(): HttpKernel
    {
        $project = new ProjectLoader()->load(dirname(__DIR__));
        $factory = new ApplicationFactory();
        // The real project: its trunk.php, config/*.php and development container; override settings here.
        $runtime = $factory->runtime($project, ['APP_ENV' => 'local', 'APP_DEBUG' => '0', 'LOG_CHANNEL' => 'null']);

        return new HttpKernelFactory()->development($factory->create($project, $runtime), new ModuleManifest($project->modules));
    }

    public function test_the_home_page_renders(): void
    {
        $response = self::kernel()->handle(new ServerRequest('GET', 'http://app.test/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Demo</h1>', (string) $response->getBody());
    }

    public function test_unknown_paths_are_a_json_404_for_api_clients(): void
    {
        $response = self::kernel()->handle(new ServerRequest('GET', 'http://app.test/nope', ['Accept' => 'application/json']));

        self::assertSame(404, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('X-Request-Id'));
    }
}
```

(`ServerRequest` is `Trunk\Http\Message\ServerRequest`; any PSR-7 request works.) To exercise a database, point `DB_DATABASE` at a temporary SQLite file in the runtime overrides and run your migrations there. Use the same style to test the compiled build: run `trunk build`, then use `HttpKernelFactory::compiled(...)`.

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

(Also `..._PASSWORD` and `..._PORT` for MySQL.) Without them 26 tests skip; with them, the reference run is 1577 tests, 0 skipped.

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

GitHub Actions has never run; browsers other than Chrome; a production web server (php-fpm with nginx, FrankenPHP, RoadRunner) in place of PHP's built-in server; realistic large applications; comparisons with other frameworks.
