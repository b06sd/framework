<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Support\Directory;
use Trunk\Tests\Support\AuthApp;
use Trunk\Tests\Support\AuthClient;

/**
 * Sign-in, CSRF, fixation, logout, timeouts and throttling through the real kernel, in both the
 * development and the compiled container.
 */
final class SessionLoginFlowTest extends TestCase
{
    private ?AuthApp $app = null;

    protected function tearDown(): void
    {
        $this->app?->cleanUp();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        yield 'development' => ['development'];
        yield 'compiled' => ['compiled'];
    }

    #[DataProvider('modes')]
    public function test_signing_in_creates_a_session_and_the_user_is_known_on_the_next_request(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $id = $app->createUser('ada@example.com');
        $client = $app->client($mode);
        $token = $client->csrf();

        // Act
        $login = $client->post('/web/login', ['email' => 'ada@example.com', 'password' => 'correct horse battery', '_csrf' => $token]);
        $me = $client->get('/web/me');

        // Assert
        self::assertSame(200, $login->getStatusCode());
        self::assertSame($id, $client->json($login)['id']);
        self::assertSame(['id' => $id], $client->json($me));
        self::assertSame('Cookie', $me->getHeaderLine('Vary'));
    }

    #[DataProvider('modes')]
    public function test_the_session_id_changes_at_login_and_the_pre_login_id_and_token_stop_working(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $app->createUser('ada@example.com');
        $client = $app->client($mode);
        $token = $client->csrf();
        $before = $client->sessionCookie();

        // Act
        $client->post('/web/login', ['email' => 'ada@example.com', 'password' => 'correct horse battery', '_csrf' => $token]);
        $after = $client->sessionCookie();
        $attacker = $app->client($mode);
        $attacker->cookies = ['session' => (string) $before];
        $replay = $attacker->get('/web/me');
        $oldToken = $client->post('/web/logout', ['_csrf' => $token]);

        // Assert
        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertNotSame($before, $after, 'session fixation: the id must change at login');
        self::assertSame(401, $replay->getStatusCode(), 'the id from before login must be dead');
        self::assertSame(403, $oldToken->getStatusCode());
        self::assertSame('CSRF_TOKEN_INVALID', $this->errorCode($client, $oldToken), 'the CSRF secret rotates at login');
    }

    #[DataProvider('modes')]
    public function test_wrong_password_and_unknown_user_are_indistinguishable(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $app->createUser('ada@example.com');
        $results = [];

        // Act
        foreach (['ada@example.com' => 'wrong password here', 'nobody@example.com' => 'correct horse battery', '' => 'x', "ada@example.com\0" => 'correct horse battery'] as $email => $password) {
            $client = $app->client($mode, '198.51.100.' . (\count($results) + 1));
            $response = $client->post('/web/login', ['email' => (string) $email, 'password' => $password, '_csrf' => $client->csrf()]);
            $results[] = [$response->getStatusCode(), (string) $response->getBody(), $client->sessionCookie() !== null];
        }

        // Assert
        self::assertSame(401, $results[0][0]);
        self::assertSame($results[0], $results[1]);
        self::assertSame($results[0], $results[2]);
        self::assertSame($results[0], $results[3]);
        self::assertStringNotContainsString('exist', $results[0][1]);
    }

    #[DataProvider('modes')]
    public function test_csrf_protection_rejects_missing_wrong_foreign_and_cross_origin_tokens(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $app->createUser('ada@example.com');
        $client = $app->client($mode);
        $good = $client->csrf();
        $other = $app->client($mode);
        $foreign = $other->csrf();
        $form = ['email' => 'ada@example.com', 'password' => 'correct horse battery'];

        // Act
        $missing = $client->post('/web/login', $form);
        $wrong = $client->post('/web/login', $form + ['_csrf' => str_repeat('A', 86)]);
        $notForMe = $client->post('/web/login', $form + ['_csrf' => $foreign]);
        $garbage = $client->post('/web/login', $form + ['_csrf' => "not a token\r\n"]);
        $crossOrigin = $client->post('/web/login', $form + ['_csrf' => $good], ['Origin' => 'https://evil.example']);
        $crossSite = $client->post('/web/login', $form + ['_csrf' => $good], ['Sec-Fetch-Site' => 'cross-site']);
        $sameSiteSubdomain = $client->post('/web/login', $form + ['_csrf' => $good], ['Sec-Fetch-Site' => 'same-site']);
        $nullOrigin = $client->post('/web/login', $form + ['_csrf' => $good], ['Origin' => 'null']);
        $viaHeader = $client->send('POST', '/web/login', $form, ['X-CSRF-Token' => $good, 'Origin' => 'http://app.test', 'Sec-Fetch-Site' => 'same-origin']);

        // Assert
        foreach ([$missing, $wrong, $notForMe, $garbage, $crossOrigin, $crossSite, $sameSiteSubdomain, $nullOrigin] as $rejected) {
            self::assertSame(403, $rejected->getStatusCode());
            self::assertSame('CSRF_TOKEN_INVALID', $this->errorCode($client, $rejected));
            self::assertStringNotContainsString($good, (string) $rejected->getBody());
        }

        self::assertSame(200, $viaHeader->getStatusCode(), 'the header form of the token is accepted from a same-origin browser');
    }

    #[DataProvider('modes')]
    public function test_masked_tokens_differ_every_time_yet_all_verify(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $app->createUser('ada@example.com');
        $client = $app->client($mode);

        // Act
        $tokens = [$client->csrf(), $client->csrf(), $client->csrf()];

        // Assert
        self::assertCount(3, array_unique($tokens), 'BREACH: the same value must never repeat');

        foreach ($tokens as $token) {
            self::assertSame(86, \strlen($token));
            self::assertSame(200, $client->post('/web/put/a/b', ['_csrf' => $token])->getStatusCode());
        }
    }

    #[DataProvider('modes')]
    public function test_safe_methods_need_no_token_and_unsafe_ones_always_do(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $client = $app->client($mode);

        // Act & Assert
        self::assertSame(200, $client->get('/web/get/x')->getStatusCode());

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $response = $client->send($method, '/web/put/a/b', []);
            self::assertContains($response->getStatusCode(), [403, 405], $method);
        }
    }

    #[DataProvider('modes')]
    public function test_logout_ends_the_session_and_removes_the_cookie(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $app->createUser('ada@example.com');
        $client = $app->client($mode);
        $client->post('/web/login', ['email' => 'ada@example.com', 'password' => 'correct horse battery', '_csrf' => $client->csrf()]);
        $cookie = (string) $client->sessionCookie();

        // Act
        $logout = $client->post('/web/logout', ['_csrf' => $client->csrf()]);
        $stolen = $app->client($mode);
        $stolen->cookies = ['session' => $cookie];

        // Assert
        self::assertSame(200, $logout->getStatusCode());
        self::assertNull($client->sessionCookie());
        self::assertStringContainsString('Max-Age=0', $logout->getHeaderLine('Set-Cookie'));
        self::assertSame(401, $client->get('/web/me')->getStatusCode());
        self::assertSame(401, $stolen->get('/web/me')->getStatusCode(), 'the old cookie is worthless after logout');
    }

    #[DataProvider('modes')]
    public function test_browsers_are_sent_to_the_login_page_and_back_but_api_clients_get_401(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $app->createUser('ada@example.com');
        $client = $app->client($mode);

        // Act
        $browser = $client->get('/web/page', ['Accept' => 'text/html,application/xhtml+xml']);
        $api = $client->get('/web/page');
        $login = $client->post('/web/login', ['email' => 'ada@example.com', 'password' => 'correct horse battery', '_csrf' => $client->csrf()]);

        // Assert
        self::assertSame(302, $browser->getStatusCode());
        self::assertSame('/login', $browser->getHeaderLine('Location'));
        self::assertSame(401, $api->getStatusCode());
        self::assertSame('AUTHENTICATION_REQUIRED', $this->errorCode($client, $api));
        self::assertSame('/web/page', $client->json($login)['next'], 'the interrupted page is where the login continues');
    }

    #[DataProvider('modes')]
    public function test_changing_the_session_version_signs_the_user_out_everywhere(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $id = $app->createUser('ada@example.com');
        $client = $app->client($mode);
        $client->post('/web/login', ['email' => 'ada@example.com', 'password' => 'correct horse battery', '_csrf' => $client->csrf()]);
        self::assertSame(200, $client->get('/web/me')->getStatusCode());

        // Act
        $app->connection->table('users')->where('id', '=', $id)->update(['session_version' => '2']);

        // Assert
        self::assertSame(401, $client->get('/web/me')->getStatusCode());
        self::assertSame(0, $app->connection->table('trunk_sessions')->count(), 'the stale session is deleted, not merely ignored');
        self::assertSame(401, $client->get('/web/me')->getStatusCode());
    }

    #[DataProvider('modes')]
    public function test_a_deleted_user_is_signed_out(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $id = $app->createUser('ada@example.com');
        $client = $app->client($mode);
        $client->post('/web/login', ['email' => 'ada@example.com', 'password' => 'correct horse battery', '_csrf' => $client->csrf()]);

        // Act
        $app->connection->table('users')->where('id', '=', $id)->delete();

        // Assert
        self::assertSame(401, $client->get('/web/me')->getStatusCode());
    }

    #[DataProvider('modes')]
    public function test_repeated_failures_lock_the_account_for_that_address_only_and_success_clears_the_count(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp(['throttle' => ['max_attempts' => 3, 'max_attempts_per_ip' => 10, 'window' => 600]]);
        $app->createUser('ada@example.com');
        $attacker = $app->client($mode, '198.51.100.9');
        $owner = $app->client($mode, '203.0.113.50');
        $wrong = static fn(AuthClient $c) => $c->post('/web/login', ['email' => 'ada@example.com', 'password' => 'not the password', '_csrf' => $c->csrf()]);

        // Act
        $codes = array_map(static fn() => $wrong($attacker)->getStatusCode(), range(1, 3));
        $locked = $attacker->post('/web/login', ['email' => 'ada@example.com', 'password' => 'correct horse battery', '_csrf' => $attacker->csrf()]);
        $ownerLogin = $owner->post('/web/login', ['email' => 'ada@example.com', 'password' => 'correct horse battery', '_csrf' => $owner->csrf()]);

        // Assert
        self::assertSame([401, 401, 401], $codes);
        self::assertSame(429, $locked->getStatusCode(), 'even the right password is refused while locked');
        self::assertSame('TOO_MANY_REQUESTS', $this->errorCode($attacker, $locked));
        self::assertGreaterThan(0, (int) $locked->getHeaderLine('Retry-After'));
        self::assertLessThanOrEqual(600, (int) $locked->getHeaderLine('Retry-After'));
        self::assertSame(200, $ownerLogin->getStatusCode(), 'a different address is not locked out by someone else\'s guesses');
    }

    #[DataProvider('modes')]
    public function test_one_address_guessing_many_accounts_is_stopped_by_the_address_limit(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp(['throttle' => ['max_attempts' => 3, 'max_attempts_per_ip' => 6, 'window' => 600]]);
        $client = $app->client($mode, '198.51.100.20');

        // Act
        $codes = [];
        for ($i = 0; $i < 8; ++$i) {
            $codes[] = $client->post('/web/login', ['email' => 'user' . $i . '@example.com', 'password' => 'x', '_csrf' => $client->csrf()])->getStatusCode();
        }

        // Assert
        self::assertSame([401, 401, 401, 401, 401, 401, 429, 429], $codes);
    }

    #[DataProvider('modes')]
    public function test_variants_of_an_identifier_share_one_throttle_counter(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp(['throttle' => ['max_attempts' => 3, 'max_attempts_per_ip' => 30, 'window' => 600]]);
        $client = $app->client($mode, '198.51.100.30');

        // Act
        $codes = [];
        foreach (['ada@example.com', 'ADA@example.com', ' ada@example.com ', 'Ada@Example.COM', 'ada@example.com'] as $variant) {
            $codes[] = $client->post('/web/login', ['email' => $variant, 'password' => 'x', '_csrf' => $client->csrf()])->getStatusCode();
        }

        // Assert
        self::assertSame([401, 401, 401, 429, 429], $codes, 'case and spacing cannot be used to get fresh attempts');
    }

    public function test_an_outdated_password_hash_is_upgraded_on_login_without_signing_anyone_out(): void
    {
        // Arrange
        $app = $this->app = new AuthApp(['password' => ['memory_cost' => 16384, 'time_cost' => 1]]);
        $id = $app->createUser('ada@example.com');
        $before = $this->column($app, $id, 'password');
        $client = $app->client('development');

        // Act
        $client->post('/web/login', ['email' => 'ada@example.com', 'password' => 'correct horse battery', '_csrf' => $client->csrf()]);
        $after = $this->column($app, $id, 'password');

        // Assert
        self::assertStringContainsString('m=8192', $before);
        self::assertStringContainsString('m=16384', $after);
        self::assertSame('1', $this->column($app, $id, 'session_version'));
        self::assertSame(200, $client->get('/web/me')->getStatusCode());
    }

    public function test_the_login_cookie_is_httponly_samesite_and_scoped_to_the_site(): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $app->createUser('ada@example.com');
        $client = $app->client('development');

        // Act
        $client->post('/web/login', ['email' => 'ada@example.com', 'password' => 'correct horse battery', '_csrf' => $client->csrf()]);
        $header = end($client->received);

        // Assert
        self::assertMatchesRegularExpression('/^session=[A-Za-z0-9_-]{43}; Path=\/; HttpOnly; SameSite=Lax$/', (string) $header);
        self::assertStringNotContainsString('Domain', (string) $header);
    }

    public function test_the_secure_configuration_uses_a_host_prefixed_secure_cookie(): void
    {
        // Arrange
        $app = $this->app = new AuthApp(['session' => ['secure' => true]]);
        $client = $app->client('development');

        // Act
        $client->csrf();

        // Assert
        self::assertMatchesRegularExpression('/^__Host-session=[A-Za-z0-9_-]{43}; Path=\/; Secure; HttpOnly; SameSite=Lax$/', (string) end($client->received));
    }

    public function test_the_file_session_store_works_the_same_end_to_end(): void
    {
        // Arrange
        $path = sys_get_temp_dir() . '/trunk-fs-' . bin2hex(random_bytes(4));
        $app = $this->app = new AuthApp(['session' => ['store' => 'file', 'path' => $path]]);
        $app->createUser('ada@example.com');
        $client = $app->client('development');

        // Act
        $client->post('/web/login', ['email' => 'ada@example.com', 'password' => 'correct horse battery', '_csrf' => $client->csrf()]);
        $me = $client->get('/web/me');
        $files = glob($path . '/*.session') ?: [];
        new Directory()->remove($path);

        // Assert
        self::assertSame(200, $me->getStatusCode());
        self::assertCount(1, $files);
        self::assertStringNotContainsString((string) $client->sessionCookie(), $files[0], 'the file is named by the hash, not the id');
    }

    #[DataProvider('modes')]
    public function test_one_address_cannot_mass_produce_anonymous_sessions_but_others_and_existing_sessions_are_unaffected(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp(['throttle' => ['max_new_sessions_per_ip' => 5, 'max_attempts' => 5, 'max_attempts_per_ip' => 30, 'window' => 600]]);
        $app->createUser('ada@example.com');
        $flood = '198.51.100.77';
        $codes = [];
        $limited = null;

        // Act
        for ($i = 0; $i < 8; ++$i) {
            $client = $app->client($mode, $flood);
            $response = $client->get('/web/csrf');
            $codes[] = $response->getStatusCode();
            $limited = $response->getStatusCode() === 429 ? $response : $limited;
        }

        $rows = $app->connection->table('trunk_sessions')->count();
        $elsewhere = $app->client($mode, '203.0.113.99')->get('/web/csrf');

        // Assert
        self::assertSame([200, 200, 200, 200, 200, 429, 429, 429], $codes);
        self::assertSame(5, $rows, 'refused requests store nothing');
        self::assertSame(200, $elsewhere->getStatusCode());
        self::assertNotNull($limited);
        self::assertSame('TOO_MANY_REQUESTS', $this->errorCode($app->client($mode), $limited));
        self::assertGreaterThan(0, (int) $limited->getHeaderLine('Retry-After'));
        self::assertSame([], $limited->getHeader('Set-Cookie'), 'a refused request sets no cookie');
    }

    #[DataProvider('modes')]
    public function test_a_signed_in_user_keeps_working_from_an_address_that_used_up_its_anonymous_allowance(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp(['throttle' => ['max_new_sessions_per_ip' => 2, 'max_attempts' => 5, 'max_attempts_per_ip' => 30, 'window' => 600]]);
        $app->createUser('ada@example.com');
        $client = $app->client($mode, '198.51.100.88');
        $token = $client->csrf();

        // Act: the address has started 1 session; start the second (this login), then use it
        $login = $client->post('/web/login', ['email' => 'ada@example.com', 'password' => 'correct horse battery', '_csrf' => $token]);
        $afterwards = [$client->get('/web/me')->getStatusCode(), $client->post('/web/put/a/b', ['_csrf' => $client->csrf()])->getStatusCode()];
        $stranger = $app->client($mode, '198.51.100.88')->get('/web/csrf');
        $stranger2 = $app->client($mode, '198.51.100.88')->get('/web/csrf');

        // Assert
        self::assertSame(200, $login->getStatusCode());
        self::assertSame([200, 200], $afterwards);
        self::assertSame(200, $stranger->getStatusCode());
        self::assertSame(429, $stranger2->getStatusCode(), 'the allowance is for anonymous visitors only');
    }

    #[DataProvider('modes')]
    public function test_flash_messages_survive_exactly_one_request_through_the_kernel(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $client = $app->client($mode);

        // Act
        $client->get('/web/flash/set');
        $first = $client->json($client->get('/web/flash/get'));
        $second = $client->json($client->get('/web/flash/get'));

        // Assert
        self::assertSame('Saved', $first['notice']);
        self::assertNull($second['notice']);
    }

    private function column(AuthApp $app, string $id, string $column): string
    {
        $value = $app->connection->table('users')->where('id', '=', $id)->value($column);

        return \is_scalar($value) ? (string) $value : '';
    }

    private function errorCode(AuthClient $client, \Psr\Http\Message\ResponseInterface $response): mixed
    {
        $error = $client->json($response)['error'] ?? null;

        return \is_array($error) ? ($error['code'] ?? null) : null;
    }
}
