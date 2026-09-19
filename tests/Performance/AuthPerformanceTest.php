<?php

declare(strict_types=1);

namespace Trunk\Tests\Performance;

use PHPUnit\Framework\TestCase;
use Trunk\Auth\Auth;
use Trunk\Auth\Authorization\Gate;
use Trunk\Auth\Csrf\CsrfTokens;
use Trunk\Auth\Session\Session;
use Trunk\Auth\Throttle\AttemptCounter;
use Trunk\Auth\Throttle\LoginThrottle;
use Trunk\Http\Message\ServerRequest;
use Trunk\Tests\Fixtures\Auth\ManageUsers;
use Trunk\Tests\Fixtures\Auth\Post;
use Trunk\Tests\Fixtures\Auth\PostPolicy;
use Trunk\Tests\Support\AuthApp;
use Trunk\Tests\Support\AuthHarness;

/**
 * What authentication costs, in numbers (printed to stderr; the assertions are deliberately loose).
 * Password hashing is slow on purpose, so it is measured separately from everything else.
 */
final class AuthPerformanceTest extends TestCase
{
    public function test_argon2id_cost_by_parameters_and_the_whole_login_step(): void
    {
        // Arrange
        $password = 'correct horse battery';
        $settings = [['8 MiB, 1 pass', 8192, 1], ['19 MiB, 2 passes (OWASP minimum)', 19456, 2], ['47 MiB, 1 pass', 47104, 1], ['64 MiB, 4 passes (our default)', 65536, 4]];

        foreach ($settings as [$label, $memory, $time]) {
            // Act
            $hashes = [];
            $verifies = [];
            $hash = '';

            for ($i = 0; $i < 5; ++$i) {
                $start = hrtime(true);
                $hash = password_hash($password, \PASSWORD_ARGON2ID, ['memory_cost' => $memory, 'time_cost' => $time, 'threads' => 1]);
                $hashes[] = (hrtime(true) - $start) / 1e6;
                $start = hrtime(true);
                password_verify($password, $hash);
                $verifies[] = (hrtime(true) - $start) / 1e6;
            }

            // Assert (report)
            sort($hashes);
            sort($verifies);
            $this->line(\sprintf('argon2id %-34s hash %6.1f ms  verify %6.1f ms  (p50 of 5)', $label, $hashes[2], $verifies[2]));
        }

        self::assertNotSame('', $hash);
    }

    public function test_login_and_the_request_paths_of_an_authenticated_application(): void
    {
        // Arrange
        $app = new AuthApp(['password' => ['memory_cost' => 65536, 'time_cost' => 4]]);
        $id = $app->createUser('ada@example.com');
        $loginHasher = new \Trunk\Auth\Password\NativePasswordHasher(new \Trunk\Auth\Settings\PasswordSettings(memoryCost: 65536, timeCost: 4));
        $app->connection->table('users')->where('id', '=', $id)->update(['password' => $loginHasher->hash('correct horse battery')]);

        foreach (['development', 'compiled'] as $mode) {
            $kernel = $app->kernel($mode);
            $browser = $app->client($mode, '203.0.113.10');

            // Act: one real login (default argon2 settings) then the steady-state paths
            $token = $browser->csrf();
            $start = hrtime(true);
            $login = $browser->post('/web/login', ['email' => 'ada@example.com', 'password' => 'correct horse battery', '_csrf' => $token]);
            $loginMs = (hrtime(true) - $start) / 1e6;
            $bearer = $app->tokens()->issue($app->user($id), 'bench')->plainText;
            $cookies = $browser->cookies;

            $plain = $this->sample(fn() => $kernel->handle($this->request('/plain')), 3000);
            $anonymous = $this->sample(fn() => $kernel->handle($this->request('/web/get/x', $cookies === [] ? [] : [])), 3000);
            $session = $this->sample(fn() => $kernel->handle($this->request('/web/me', $cookies)), 3000);
            $token = $this->sample(fn() => $kernel->handle($this->request('/api/whoami', [], $bearer)), 3000);
            $csrfIssue = $this->sample(fn() => $kernel->handle($this->request('/web/csrf', $cookies)), 3000);

            // Assert (report)
            $this->line(\sprintf('[%s] login request (argon2id 64 MiB/4, includes DB session write)  %8.1f ms', $mode, $loginMs));
            $this->line(\sprintf('[%s] route without auth                                 %s', $mode, $this->stats($plain)));
            $this->line(\sprintf('[%s] session middleware, no cookie (anonymous)          %s', $mode, $this->stats($anonymous)));
            $this->line(\sprintf('[%s] session + RequireLogin, signed in                  %s', $mode, $this->stats($session)));
            $this->line(\sprintf('[%s] bearer token (RequireToken)                        %s', $mode, $this->stats($token)));
            $this->line(\sprintf('[%s] CSRF token issue (signed in session)               %s', $mode, $this->stats($csrfIssue)));
            self::assertSame(200, $login->getStatusCode());
        }

        $app->cleanUp();
    }

    public function test_the_small_parts_csrf_gate_and_throttle(): void
    {
        // Arrange
        $harness = AuthHarness::for('sqlite') ?? self::fail();
        $user = $harness->createUser('admin@example.com');
        $session = new Session();
        $session->start(null, null, $harness->clock->now());
        $tokens = new CsrfTokens();
        $issued = $tokens->token($session);
        $auth = new Auth($session, $harness->users, $harness->hasher, new LoginThrottle(new AttemptCounter($harness->connection, $harness->settings->throttle, $harness->clock), $harness->settings->throttle), new ServerRequest('GET', 'http://app.test/'));
        $auth->login($user);
        $gate = new Gate($auth, [new PostPolicy()], [new ManageUsers()]);
        $post = new Post('1', $user->authId());
        $throttle = new LoginThrottle(new AttemptCounter($harness->connection, $harness->settings->throttle, $harness->clock), $harness->settings->throttle);

        // Act
        $issue = $this->sample(static fn() => $tokens->token($session), 20000);
        $verify = $this->sample(static fn() => $tokens->verify($session, $issued), 20000);
        $allowsObject = $this->sample(static fn() => $gate->allows('update', $post), 20000);
        $allowsAbility = $this->sample(static fn() => $gate->allows('manage-users'), 20000);
        $check = $this->sample(static fn() => $throttle->assertAllowed('ada@example.com', '203.0.113.5'), 3000);

        // Assert (report)
        $this->line('CSRF token issue (mask)              ' . $this->stats($issue));
        $this->line('CSRF token verify (unmask, compare)  ' . $this->stats($verify));
        $this->line('Gate::allows (policy, user cached)   ' . $this->stats($allowsObject));
        $this->line('Gate::allows (general ability)       ' . $this->stats($allowsAbility));
        $this->line('login throttle check (2 SELECTs)     ' . $this->stats($check));
        $harness->cleanUp();
        self::assertNotEmpty($issue);
    }

    /**
     * @param array<string, string> $cookies
     */
    private function request(string $path, array $cookies = [], ?string $bearer = null): ServerRequest
    {
        $request = new ServerRequest('GET', 'http://app.test' . $path, ['Accept' => 'application/json'])->withAttribute('client_ip', '203.0.113.10');

        if ($cookies !== []) {
            $request = $request->withCookieParams($cookies);
        }

        return $bearer === null ? $request : $request->withHeader('Authorization', 'Bearer ' . $bearer);
    }

    private function line(string $text): void
    {
        fwrite(\STDERR, "\n[auth-perf] " . $text);
    }

    /**
     * @return list<float> seconds
     */
    private function sample(callable $work, int $n): array
    {
        for ($i = 0; $i < min(300, $n); ++$i) {
            $work();
        }

        $samples = [];

        for ($i = 0; $i < $n; ++$i) {
            $start = hrtime(true);
            $work();
            $samples[] = (hrtime(true) - $start) / 1e9;
        }

        return $samples;
    }

    /**
     * @param list<float> $samples seconds
     */
    private function stats(array $samples): string
    {
        sort($samples);
        $n = \count($samples);
        $pick = static fn(float $p): float => $samples[(int) min($n - 1, floor($p * $n))] * 1e6;

        return \sprintf('p50 %8.1f us  p95 %8.1f us  p99 %8.1f us', $pick(0.50), $pick(0.95), $pick(0.99));
    }
}
