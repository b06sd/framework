<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Auth;

use Closure;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Trunk\Auth\Auth;
use Trunk\Auth\Http\RequireToken;
use Trunk\Auth\Http\SessionMiddleware;
use Trunk\Auth\Session\DatabaseSessionStore;
use Trunk\Auth\Session\Session;
use Trunk\Auth\Throttle\AttemptCounter;
use Trunk\Auth\Throttle\LoginThrottle;
use Trunk\Auth\Throttle\SessionCreationLimit;
use Trunk\Auth\Token\DatabaseTokenStore;
use Trunk\Auth\Token\TokenManager;
use Trunk\Database\Connection\QueryLog;
use Trunk\Http\Message\Response;
use Trunk\Http\Message\ServerRequest;
use Trunk\Tests\Support\AuthHarness;

/**
 * A regression guard for the cost of authentication: how many database statements an authenticated
 * request needs. Every extra query on this path is paid by every logged-in request.
 */
final class AuthQueryCountTest extends TestCase
{
    private ?AuthHarness $auth = null;

    protected function tearDown(): void
    {
        $this->auth?->cleanUp();
    }

    public function test_an_authenticated_session_request_costs_one_session_read_and_one_user_read_and_no_writes(): void
    {
        // Arrange
        $log = new QueryLog();
        $harness = $this->auth = AuthHarness::for('sqlite', null, $log) ?? self::fail();
        $user = $harness->createUser('ada@example.com');
        $cookie = $this->login($harness, $user);
        $log->clear();

        // Act
        $seen = $this->authenticated($harness, $cookie);
        $reads = $this->kinds($log);
        $log->clear();
        $harness->clock->advance(61);
        $this->authenticated($harness, $cookie);
        $withTouch = $this->kinds($log);

        // Assert
        self::assertTrue($seen);
        self::assertSame(['SELECT', 'SELECT'], $reads, 'session read + user read, nothing written while the session was used a moment ago');
        self::assertSame(['SELECT', 'SELECT', 'UPDATE'], $withTouch, 'one write to extend the idle timer, at most once a minute');
    }

    public function test_an_anonymous_request_that_stores_nothing_touches_the_database_not_at_all(): void
    {
        // Arrange
        $log = new QueryLog();
        $harness = $this->auth = AuthHarness::for('sqlite', null, $log) ?? self::fail();

        // Act
        $this->pass($harness, null, static function (Session $s): void {
            $s->get('nothing');
        });

        // Assert
        self::assertSame([], $this->kinds($log));
    }

    public function test_a_bearer_token_request_costs_a_token_read_a_user_read_and_a_write_at_most_once_per_interval(): void
    {
        // Arrange
        $log = new QueryLog();
        $harness = $this->auth = AuthHarness::for('sqlite', null, $log) ?? self::fail();
        $user = $harness->createUser('ada@example.com');
        $manager = new TokenManager(new DatabaseTokenStore($harness->connection, $harness->settings->tokens), $harness->settings->tokens, $harness->clock);
        $token = $manager->issue($user, 'ci');
        $log->clear();

        // Act
        $first = $this->bearer($harness, $manager, $token->plainText);
        $firstKinds = $this->kinds($log);
        $log->clear();
        $this->bearer($harness, $manager, $token->plainText);
        $secondKinds = $this->kinds($log);

        // Assert
        self::assertSame(200, $first);
        self::assertSame(['SELECT', 'UPDATE', 'SELECT'], $firstKinds, 'token read, note first use, user read');
        self::assertSame(['SELECT', 'SELECT'], $secondKinds, 'no write again inside the touch interval');
    }

    /**
     * @return list<string>
     */
    private function kinds(QueryLog $log): array
    {
        return array_map(static fn(array $e): string => strtoupper(strtok(ltrim((string) $e['sql']), ' ') ?: ''), $log->entries());
    }

    private function login(AuthHarness $harness, \Trunk\Auth\User\DatabaseUser $user): string
    {
        $response = $this->pass($harness, null, function (Session $session, Auth $auth) use ($user): void {
            $auth->login($user);
        });
        preg_match('/session=([A-Za-z0-9_-]{43})/', $response->getHeaderLine('Set-Cookie'), $m);

        return $m[1] ?? self::fail('no session cookie');
    }

    private function authenticated(AuthHarness $harness, string $cookie): bool
    {
        $seen = false;
        $this->pass($harness, $cookie, static function (Session $session, Auth $auth) use (&$seen): void {
            $seen = $auth->check();
        });

        return $seen;
    }

    private function bearer(AuthHarness $harness, TokenManager $manager, string $plain): int
    {
        $session = new Session();
        $auth = new Auth($session, $harness->users, $harness->hasher, $this->throttle($harness), new ServerRequest('GET', 'http://app.test/'));
        $middleware = new RequireToken($auth, $manager, $harness->users);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        return $middleware->process(new ServerRequest('GET', 'http://app.test/')->withHeader('Authorization', 'Bearer ' . $plain), $handler)->getStatusCode();
    }

    /**
     * @param Closure(Session, Auth): void $inside
     */
    private function pass(AuthHarness $harness, ?string $cookie, Closure $inside): ResponseInterface
    {
        $session = new Session();
        $auth = new Auth($session, $harness->users, $harness->hasher, $this->throttle($harness), new ServerRequest('GET', 'http://app.test/'));
        $settings = $harness->settings;
        $counter = new AttemptCounter($harness->connection, $settings->throttle, $harness->clock);
        $middleware = new SessionMiddleware($session, new DatabaseSessionStore($harness->connection, $settings->session->table), $settings->session, $harness->clock, new SessionCreationLimit($counter, $settings->throttle));
        $request = new ServerRequest('GET', 'http://app.test/')->withAttribute('client_ip', '203.0.113.5');

        if ($cookie !== null) {
            $request = $request->withCookieParams([$settings->session->cookieName() => $cookie]);
        }

        $handler = new class ($session, $auth, $inside) implements RequestHandlerInterface {
            public function __construct(private Session $session, private Auth $auth, private Closure $inside) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->inside)($this->session, $this->auth);

                return new Response(200);
            }
        };

        return $middleware->process($request, $handler);
    }

    private function throttle(AuthHarness $harness): LoginThrottle
    {
        return new LoginThrottle(new AttemptCounter($harness->connection, $harness->settings->throttle, $harness->clock), $harness->settings->throttle);
    }
}
