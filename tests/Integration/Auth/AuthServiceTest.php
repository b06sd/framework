<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use Trunk\Auth\Auth;
use Trunk\Auth\Password\PasswordHasher;
use Trunk\Auth\Session\Session;
use Trunk\Auth\Throttle\AttemptCounter;
use Trunk\Auth\Throttle\LoginThrottle;
use Trunk\Auth\User\DatabaseUser;
use Trunk\Http\Message\ServerRequest;
use Trunk\Tests\Support\AuthHarness;
use Trunk\Tests\Support\SpyPasswordHasher;

/**
 * The parts of `Auth::attempt()` that a full request cannot show: how much hashing work each path
 * does (so timing cannot reveal accounts), and what a failing rehash does.
 */
final class AuthServiceTest extends TestCase
{
    private ?AuthHarness $auth = null;

    protected function tearDown(): void
    {
        $this->auth?->cleanUp();
    }

    public function test_an_unknown_user_costs_one_hash_computation_just_like_a_wrong_password(): void
    {
        // Arrange
        $spy = new SpyPasswordHasher();
        $harness = $this->auth = AuthHarness::for('sqlite') ?? self::fail();
        $harness->createUser('ada@example.com');
        $auth = $this->auth($harness, $spy);

        // Act
        $auth->attempt('nobody@example.com', 'whatever password');
        [$hashesUnknown, $verifiesUnknown] = [$spy->hashes, $spy->verifies];
        $auth->attempt('ada@example.com', 'wrong password here');
        [$hashesWrong, $verifiesWrong] = [$spy->hashes - $hashesUnknown, $spy->verifies - $verifiesUnknown];

        // Assert: one expensive computation each way, whichever it is
        self::assertSame(1, $hashesUnknown + $verifiesUnknown);
        self::assertSame(1, $hashesWrong + $verifiesWrong);
        self::assertSame([1, 0], [$hashesUnknown, $verifiesUnknown], 'an unknown user burns a hash instead of returning early');
        self::assertSame([0, 1], [$hashesWrong, $verifiesWrong]);
    }

    public function test_an_account_without_a_password_also_burns_a_hash_instead_of_returning_early(): void
    {
        // Arrange
        $spy = new SpyPasswordHasher();
        $harness = $this->auth = AuthHarness::for('sqlite') ?? self::fail();
        $harness->connection->table('trunk_at_users')->insert(['name' => 'Social', 'email' => 'social@example.com', 'password' => '', 'session_version' => '1']);
        $auth = $this->auth($harness, $spy);

        // Act
        $result = $auth->attempt('social@example.com', 'any password at all');

        // Assert
        self::assertNull($result);
        self::assertSame([1, 0], [$spy->hashes, $spy->verifies]);
    }

    public function test_a_password_too_short_for_todays_rules_still_logs_in_and_is_simply_not_upgraded(): void
    {
        // Arrange
        $harness = $this->auth = AuthHarness::for('sqlite') ?? self::fail();
        $old = password_hash('short', \PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]);
        $harness->connection->table('trunk_at_users')->insert(['name' => 'Old', 'email' => 'old@example.com', 'password' => $old, 'session_version' => '1']);
        $auth = $this->auth($harness, new \Trunk\Auth\Password\NativePasswordHasher(new \Trunk\Auth\Settings\PasswordSettings(memoryCost: 16384, timeCost: 1, minLength: 12)));

        // Act
        $user = $auth->attempt('old@example.com', 'short');

        // Assert
        self::assertInstanceOf(DatabaseUser::class, $user);
        self::assertSame($old, $harness->users->byIdentifier('old@example.com')?->authPasswordHash(), 'the hash could not be upgraded under the new length rule, and nothing broke');
    }

    public function test_bearer_token_abilities_apply_only_to_token_authenticated_requests(): void
    {
        // Arrange
        $harness = $this->auth = AuthHarness::for('sqlite') ?? self::fail();
        $user = $harness->createUser('ada@example.com');
        $auth = $this->auth($harness);

        // Act & Assert
        self::assertFalse($auth->viaToken());
        self::assertFalse($auth->tokenCan('read'));
        $auth->authenticatedByToken($user, ['read']);
        self::assertTrue($auth->viaToken());
        self::assertTrue($auth->tokenCan('read'));
        self::assertFalse($auth->tokenCan('write'));
        $auth->authenticatedByToken($user, ['*']);
        self::assertTrue($auth->tokenCan('anything'));
    }

    private function auth(AuthHarness $harness, ?PasswordHasher $hasher = null): Auth
    {
        $session = new Session();
        $session->start(null, null, $harness->clock->now());

        return new Auth(
            $session,
            $harness->users,
            $hasher ?? $harness->hasher,
            new LoginThrottle(new AttemptCounter($harness->connection, $harness->settings->throttle, $harness->clock), $harness->settings->throttle),
            new ServerRequest('POST', 'http://app.test/login')->withAttribute('client_ip', '203.0.113.5'),
        );
    }
}
