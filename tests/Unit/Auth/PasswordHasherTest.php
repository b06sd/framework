<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Trunk\Auth\Exception\InvalidPasswordException;
use Trunk\Auth\Password\NativePasswordHasher;
use Trunk\Auth\Settings\PasswordSettings;

final class PasswordHasherTest extends TestCase
{
    public function test_a_password_verifies_against_its_own_hash_and_not_against_another(): void
    {
        // Arrange
        $hasher = $this->hasher();

        // Act
        $hash = $hasher->hash('correct horse battery');

        // Assert
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify('correct horse battery', $hash));
        self::assertFalse($hasher->verify('correct horse batterx', $hash));
        self::assertNotSame($hash, $hasher->hash('correct horse battery'), 'every hash has its own salt');
    }

    public function test_bcrypt_can_be_chosen(): void
    {
        // Act
        $hasher = $this->hasher(algorithm: 'bcrypt');
        $hash = $hasher->hash('correct horse battery');

        // Assert
        self::assertStringStartsWith('$2y$', $hash);
        self::assertTrue($hasher->verify('correct horse battery', $hash));
    }

    public function test_length_rules_state_the_rule_and_never_echo_the_password(): void
    {
        // Arrange
        $hasher = $this->hasher(max: 64);

        // Act & Assert
        foreach (['short-pw' => 'at least 12 characters', str_repeat('a', 65) => 'at most 64 bytes'] as $password => $rule) {
            try {
                $hasher->hash((string) $password);
                self::fail('expected a rejection');
            } catch (InvalidPasswordException $e) {
                self::assertStringContainsString($rule, $e->getMessage());
                self::assertStringNotContainsString((string) $password, $e->getMessage());
            }
        }
    }

    public function test_an_overlong_password_is_never_hashed_when_verifying_and_an_empty_hash_never_matches(): void
    {
        // Arrange
        $hasher = $this->hasher(max: 64);
        $hash = $hasher->hash('correct horse battery');

        // Act & Assert
        self::assertFalse($hasher->verify(str_repeat('a', 1_000_000), $hash), 'a megabyte password is refused before any hashing work');
        self::assertFalse($hasher->verify('anything at all', ''));
        self::assertFalse($hasher->verify('', $hash));
    }

    public function test_a_hash_made_with_weaker_settings_needs_a_rehash(): void
    {
        // Arrange
        $weak = $this->hasher(memory: 8192);
        $strong = $this->hasher(memory: 16384);
        $hash = $weak->hash('correct horse battery');

        // Act & Assert
        self::assertFalse($weak->needsRehash($hash));
        self::assertTrue($strong->needsRehash($hash));
        self::assertTrue($weak->needsRehash('$2y$04$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234'), 'a different algorithm needs a rehash');
    }
    private function hasher(int $memory = 8192, string $algorithm = 'argon2id', int $max = 1024): NativePasswordHasher
    {
        return new NativePasswordHasher(new PasswordSettings($algorithm, $memory, 1, 1, 12, $max));
    }
}
