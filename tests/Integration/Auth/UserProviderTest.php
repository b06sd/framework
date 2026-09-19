<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\AuthHarness;

final class UserProviderTest extends TestCase
{
    private ?AuthHarness $auth = null;

    protected function tearDown(): void
    {
        $this->auth?->cleanUp();
    }

    #[DataProvider('drivers')]
    public function test_a_user_is_found_by_id_and_by_identifier_and_the_password_never_appears_in_attributes(string $driver): void
    {
        // Arrange
        $auth = $this->auth = AuthHarness::for($driver) ?? self::markTestSkipped($driver . ' is not configured.');
        $created = $auth->createUser('ada@example.com', 'correct horse battery', 'Ada', '7');

        // Act
        $byEmail = $auth->users->byIdentifier('ada@example.com');
        $byId = $auth->users->byId($created->authId());

        // Assert
        self::assertNotNull($byEmail);
        self::assertSame($created->authId(), $byEmail->authId());
        self::assertSame($created->authId(), $byId?->authId());
        self::assertSame('7', $byEmail->authSessionVersion());
        self::assertTrue($auth->hasher->verify('correct horse battery', $byEmail->authPasswordHash()));
        self::assertInstanceOf(\Trunk\Auth\User\DatabaseUser::class, $byEmail);
        self::assertSame('Ada', $byEmail->attributes['name']);
        self::assertArrayNotHasKey('password', $byEmail->attributes);
        self::assertStringNotContainsString('argon2', print_r($byEmail, true) . var_export($byEmail->__debugInfo(), true));
    }

    #[DataProvider('drivers')]
    public function test_unknown_users_and_hostile_identifiers_return_null_and_run_no_injected_sql(string $driver): void
    {
        // Arrange
        $auth = $this->auth = AuthHarness::for($driver) ?? self::markTestSkipped($driver . ' is not configured.');
        $auth->createUser('ada@example.com');

        // Act & Assert
        foreach (['nobody@example.com', "' OR '1'='1", 'ada@example.com" --', '', "ada@example.com\0"] as $identifier) {
            self::assertNull($auth->users->byIdentifier($identifier), $identifier);
        }

        self::assertNull($auth->users->byId('999999'));
        self::assertSame(1, $auth->connection->table('trunk_at_users')->count());
    }

    #[DataProvider('drivers')]
    public function test_updating_the_hash_changes_only_the_hash(string $driver): void
    {
        // Arrange
        $auth = $this->auth = AuthHarness::for($driver) ?? self::markTestSkipped($driver . ' is not configured.');
        $user = $auth->createUser('ada@example.com', 'correct horse battery', 'Ada', '3');
        $other = $auth->createUser('grace@example.com', 'another long password', 'Grace');
        $fresh = $auth->hasher->hash('a brand new password');

        // Act
        $auth->users->updatePasswordHash($user, $fresh);
        $updated = $auth->users->byId($user->authId());
        $untouched = $auth->users->byId($other->authId());

        // Assert
        self::assertSame($fresh, $updated?->authPasswordHash());
        self::assertSame('3', $updated->authSessionVersion(), 'a rehash must not sign anyone out');
        self::assertTrue($auth->hasher->verify('another long password', $untouched?->authPasswordHash() ?? ''));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        return AuthHarness::drivers();
    }
}
