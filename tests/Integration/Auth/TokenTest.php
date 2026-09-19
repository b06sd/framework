<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Auth;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Auth\Token\AccessToken;
use Trunk\Auth\Token\DatabaseTokenStore;
use Trunk\Auth\Token\TokenRecord;
use Trunk\Tests\Support\AuthApp;
use Trunk\Tests\Support\AuthHarness;

final class TokenTest extends TestCase
{
    private ?AuthHarness $auth = null;

    private ?AuthApp $app = null;

    protected function tearDown(): void
    {
        $this->auth?->cleanUp();
        $this->app?->cleanUp();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        return AuthHarness::drivers();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        yield 'development' => ['development'];
        yield 'compiled' => ['compiled'];
    }

    #[DataProvider('drivers')]
    public function test_the_store_creates_finds_touches_revokes_lists_and_prunes(string $driver): void
    {
        // Arrange
        $auth = $this->auth = AuthHarness::for($driver) ?? self::markTestSkipped($driver . ' is not configured.');
        $store = new DatabaseTokenStore($auth->connection, $auth->settings->tokens);
        $now = $auth->clock->now();
        $mk = static fn(string $id, ?int $expires = null, ?int $revoked = null): TokenRecord => new TokenRecord(new AccessToken($id, '7', '1', 'ci ' . $id, ['read', 'write:posts'], $expires, $revoked, null, $now), hash('sha256', $id));

        // Act
        $store->create($mk('aaaaaaaaaaaaaaaa'));
        $store->create($mk('bbbbbbbbbbbbbbbb', $now - 100));
        $store->create($mk('cccccccccccccccc', null, $now - 100));
        $store->create($mk('dddddddddddddddd'));
        $store->touch('aaaaaaaaaaaaaaaa', $now + 5);
        $found = $store->find('aaaaaaaaaaaaaaaa');
        $store->revoke('aaaaaaaaaaaaaaaa', $now + 10);
        $store->revoke('aaaaaaaaaaaaaaaa', $now + 99);
        $revoked = $store->find('aaaaaaaaaaaaaaaa');
        $pruned = $store->prune($now);

        // Assert
        self::assertSame(['read', 'write:posts'], $found?->token->abilities);
        self::assertSame($now + 5, $found->token->lastUsedAt);
        self::assertSame($now + 10, $revoked?->token->revokedAt, 'revoking twice keeps the first time');
        self::assertSame(2, $pruned, 'the expired and the long-revoked token go; the one revoked just now stays');
        self::assertNull($store->find('bbbbbbbbbbbbbbbb'));
        self::assertNull($store->find('missing'));
        self::assertCount(2, $store->forUser('7'));
        self::assertSame(1, $store->revokeAllFor('7', $now + 20));
        self::assertSame(0, $store->revokeAllFor('7', $now + 21));
    }

    public function test_a_token_verifies_until_it_expires_is_revoked_or_altered(): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $user = $app->user($app->createUser('ada@example.com'));
        $tokens = $app->tokens(ttl: 3600);

        // Act
        $new = $tokens->issue($user, 'ci', ['read']);
        $plain = $new->plainText;
        $app->clock->advance(10);
        $ok = $tokens->verify($plain);
        [$id, $secret] = explode('.', substr($plain, 4));
        $wrongSecret = $tokens->verify('trk_' . $id . '.' . str_repeat('A', 43));
        $unknownId = $tokens->verify('trk_' . str_repeat('A', 16) . '.' . $secret);
        $app->clock->advance(3600);
        $expired = $tokens->verify($plain);

        // Assert
        self::assertMatchesRegularExpression('/^trk_[A-Za-z0-9_-]{16}\.[A-Za-z0-9_-]{43}$/', $plain);
        self::assertSame($user->authId(), $ok?->userId);
        self::assertSame(['read'], $ok->abilities);
        self::assertNull($wrongSecret);
        self::assertNull($unknownId);
        self::assertNull($expired);
        $second = $tokens->issue($user, 'ci2');
        $tokens->revoke($second->token->id);
        self::assertNull($tokens->verify($second->plainText));
    }

    public function test_only_a_hash_of_the_secret_is_stored_and_the_secret_never_shows_in_a_dump(): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $user = $app->user($app->createUser('ada@example.com'));

        // Act
        $new = $app->tokens()->issue($user, 'ci');
        $secret = explode('.', substr($new->plainText, 4))[1];
        $rows = json_encode($app->connection->table('trunk_tokens')->get(), \JSON_THROW_ON_ERROR);

        // Assert
        self::assertStringNotContainsString($secret, $rows);
        self::assertStringNotContainsString($new->plainText, $rows);
        self::assertStringContainsString(hash('sha256', $secret), $rows);
        self::assertStringNotContainsString($secret, print_r($new, true) . var_export($new->__debugInfo(), true));
    }

    public function test_last_used_is_written_at_most_once_per_interval(): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $tokens = $app->tokens(touchInterval: 300);
        $new = $tokens->issue($app->user($app->createUser('ada@example.com')), 'ci');
        $used = static fn(): string => \is_scalar($v = $app->connection->table('trunk_tokens')->value('last_used_at')) ? (string) $v : '';

        // Act
        $tokens->verify($new->plainText);
        $first = $used();
        $app->clock->advance(100);
        $tokens->verify($new->plainText);
        $second = $used();
        $app->clock->advance(300);
        $tokens->verify($new->plainText);
        $third = $used();

        // Assert
        self::assertSame($first, $second, 'no write inside the interval');
        self::assertNotSame($first, $third);
    }

    public function test_a_token_can_be_made_to_never_expire_and_all_of_a_users_tokens_can_be_revoked(): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $user = $app->user($app->createUser('ada@example.com'));
        $tokens = $app->tokens();

        // Act
        $forever = $tokens->issue($user, 'forever', ['*'], 0);
        $other = $tokens->issue($user, 'other');
        $app->clock->advance(10 * 365 * 86_400);
        $stillValid = $tokens->verify($forever->plainText);
        $revoked = $tokens->revokeAllFor($user);

        // Assert
        self::assertNotNull($stillValid);
        self::assertNull($stillValid->expiresAt);
        self::assertSame(2, $revoked);
        self::assertNull($tokens->verify($forever->plainText));
        self::assertNull($tokens->verify($other->plainText));
        self::assertCount(2, $tokens->forUser($user));
    }

    /**
     * @return iterable<string, array{string, array<string>, ?int}>
     */
    public static function badIssue(): iterable
    {
        yield 'empty name' => ['', ['read'], null];
        yield 'control character in the name' => ["a\nb", ['read'], null];
        yield 'long name' => [str_repeat('n', 101), ['read'], null];
        yield 'no abilities' => ['ci', [], null];
        yield 'uppercase ability' => ['ci', ['Read'], null];
        yield 'ability with a space' => ['ci', ['read posts'], null];
        yield 'too many abilities' => ['ci', array_map(static fn(int $i): string => 'a' . $i, range(1, 51)), null];
        yield 'negative ttl' => ['ci', ['read'], -1];
        yield 'huge ttl' => ['ci', ['read'], 400_000_000];
    }

    /**
     * @param array<string> $abilities
     */
    #[DataProvider('badIssue')]
    public function test_bad_names_abilities_and_lifetimes_are_refused(string $name, array $abilities, ?int $ttl): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $user = $app->user($app->createUser('ada@example.com'));

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $app->tokens()->issue($user, $name, array_values($abilities), $ttl);
    }

    public function test_malformed_bearer_values_are_null_without_touching_the_database(): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $tokens = $app->tokens();

        // Act & Assert
        foreach (['', 'trk_', 'Bearer x', 'trk_' . str_repeat('A', 16), 'trk_' . str_repeat('A', 16) . '.' . str_repeat('A', 42), "trk_AAAAAAAAAAAAAAAA.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\n", "trk_' OR 1=1 --.x", str_repeat('x', 100_000)] as $bad) {
            self::assertNull($tokens->verify($bad));
        }
    }

    #[DataProvider('modes')]
    public function test_bearer_authentication_through_the_kernel_with_abilities_and_one_shared_failure_answer(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $id = $app->createUser('ada@example.com');
        $user = $app->user($id);
        $tokens = $app->tokens();
        $good = $tokens->issue($user, 'ci', ['read']);
        $revoked = $tokens->issue($user, 'old');
        $tokens->revoke($revoked->token->id);
        $orphanUser = $app->user($app->createUser('gone@example.com'));
        $orphan = $tokens->issue($orphanUser, 'orphan');
        $app->connection->table('users')->where('id', '=', $orphanUser->authId())->delete();
        $client = $app->client($mode);
        $failures = [];

        // Act
        $ok = $client->get('/api/whoami', ['Authorization' => 'Bearer ' . $good->plainText]);
        foreach ([[], ['Authorization' => 'Bearer'], ['Authorization' => 'Basic ' . base64_encode('a:b')], ['Authorization' => 'Bearer trk_' . str_repeat('A', 16) . '.' . str_repeat('A', 43)], ['Authorization' => 'Bearer ' . $revoked->plainText], ['Authorization' => 'Bearer ' . $orphan->plainText], ['Authorization' => 'Bearer ' . $good->plainText . 'x']] as $headers) {
            $response = $client->get('/api/whoami', $headers);
            $failures[] = [$response->getStatusCode(), (string) $response->getBody(), $response->getHeaderLine('WWW-Authenticate')];
        }

        // Assert
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame(['id' => $id, 'viaToken' => true, 'canRead' => true, 'canWrite' => false], $client->json($ok));
        self::assertSame([], $ok->getHeader('Set-Cookie'), 'token requests never create a session');

        foreach ($failures as $failure) {
            self::assertSame(401, $failure[0]);
            self::assertSame('Bearer', $failure[2]);
            self::assertSame(preg_replace('/"requestId":"[^"]+"/', '', $failures[0][1]), preg_replace('/"requestId":"[^"]+"/', '', $failure[1]));
        }
    }

    #[DataProvider('modes')]
    public function test_changing_the_owners_session_version_ends_their_tokens_and_their_sessions_together(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $id = $app->createUser('ada@example.com');
        $token = $app->tokens()->issue($app->user($id), 'ci');
        $bearer = ['Authorization' => 'Bearer ' . $token->plainText];
        $api = $app->client($mode);
        $web = $app->client($mode);
        $web->post('/web/login', ['email' => 'ada@example.com', 'password' => 'correct horse battery', '_csrf' => $web->csrf()]);

        // Act
        $before = [$api->get('/api/whoami', $bearer)->getStatusCode(), $web->get('/web/me')->getStatusCode()];
        $app->connection->table('users')->where('id', '=', $id)->update(['session_version' => '2']);
        $after = [$api->get('/api/whoami', $bearer)->getStatusCode(), $web->get('/web/me')->getStatusCode()];
        $app->connection->table('users')->where('id', '=', $id)->update(['session_version' => '1']);
        $restored = $api->get('/api/whoami', $bearer)->getStatusCode();

        // Assert
        self::assertSame('1', $app->connection->table('trunk_tokens')->value('user_version'), 'the token remembers the version it was issued under');
        self::assertSame([200, 200], $before);
        self::assertSame([401, 401], $after, 'a password change (version bump) ends the token as well as the session');
        self::assertSame(200, $restored, 'the token is tied to the value, so only the identical version matches again');
    }

    #[DataProvider('modes')]
    public function test_a_token_does_not_sign_in_to_session_routes_and_a_session_cookie_does_not_pass_the_token_guard(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $user = $app->user($app->createUser('ada@example.com'));
        $token = $app->tokens()->issue($user, 'ci');
        $client = $app->client($mode);
        $client->post('/web/login', ['email' => 'ada@example.com', 'password' => 'correct horse battery', '_csrf' => $client->csrf()]);
        $bearerOnly = $app->client($mode);

        // Act & Assert
        self::assertSame(401, $bearerOnly->get('/web/me', ['Authorization' => 'Bearer ' . $token->plainText])->getStatusCode());
        self::assertSame(200, $client->get('/web/me')->getStatusCode());
        self::assertSame(401, $client->get('/api/whoami')->getStatusCode(), 'ambient cookies never authenticate a token route');
    }
}
