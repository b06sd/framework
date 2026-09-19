<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Auth;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Auth\Settings\AuthSettings;
use Trunk\Auth\Settings\PasswordSettings;
use Trunk\Auth\Settings\SessionSettings;
use Trunk\Auth\Settings\ThrottleSettings;
use Trunk\Auth\Settings\UserSettings;
use Trunk\Foundation\Configuration;

final class SettingsTest extends TestCase
{
    public function test_defaults_are_valid_and_the_cookie_gets_the_host_prefix_only_when_secure(): void
    {
        // Act
        $settings = new AuthSettings();

        // Assert
        self::assertSame('__Host-session', $settings->session->cookieName());
        self::assertSame('trunk_x', new SessionSettings(cookie: 'trunk_x', secure: false)->cookieName());
    }

    public function test_settings_are_read_from_configuration_with_defaults_for_missing_keys(): void
    {
        // Arrange
        $configuration = new Configuration(['auth' => ['session' => ['idle_timeout' => 600, 'secure' => false], 'login_path' => '/signin', 'users' => ['table' => 'accounts']]]);

        // Act
        $settings = AuthSettings::fromConfiguration($configuration);

        // Assert
        self::assertSame(600, $settings->session->idleTimeout);
        self::assertFalse($settings->session->secure);
        self::assertSame('/signin', $settings->loginPath);
        self::assertSame('accounts', $settings->users->table);
        self::assertSame('email', $settings->users->identifier);
        self::assertSame(5, $settings->throttle->maxAttempts);
    }

    /**
     * @return iterable<string, array{callable(): AuthSettings, string}>
     */
    public static function invalid(): iterable
    {
        yield 'sql in a table name' => [static fn() => new AuthSettings(users: new UserSettings(table: 'users; DROP TABLE x')), 'auth.users.table must be a plain table or column name'];
        yield 'unknown algorithm' => [static fn() => new AuthSettings(new PasswordSettings(algorithm: 'md5')), 'auth.password.algorithm must be argon2id or bcrypt'];
        yield 'too weak argon2' => [static fn() => new AuthSettings(new PasswordSettings(memoryCost: 8)), 'out of range'];
        yield 'short minimum length' => [static fn() => new AuthSettings(new PasswordSettings(minLength: 4)), 'min_length must be at least 8'];
        yield 'unknown store' => [static fn() => new AuthSettings(session: new SessionSettings(store: 'redis')), 'auth.session.store must be database, file or array'];
        yield 'file store without a path' => [static fn() => new AuthSettings(session: new SessionSettings(store: 'file', path: '')), 'auth.session.path is required'];
        yield 'idle above lifetime' => [static fn() => new AuthSettings(session: new SessionSettings(idleTimeout: 9000, lifetime: 3600)), 'idle_timeout must be at least 60 seconds and no more than lifetime'];
        yield 'same site none without secure' => [static fn() => new AuthSettings(session: new SessionSettings(sameSite: 'None', secure: false)), 'requires auth.session.secure = true'];
        yield 'bad cookie name' => [static fn() => new AuthSettings(session: new SessionSettings(cookie: 'Bad Name;')), 'auth.session.cookie'];
        yield 'per ip below per identifier' => [static fn() => new AuthSettings(throttle: new ThrottleSettings(maxAttempts: 10, maxAttemptsPerIp: 5)), 'max_attempts_per_ip at least max_attempts'];
        yield 'open redirect login path' => [static fn() => new AuthSettings(loginPath: '//evil.example'), 'auth.login_path must be a local path'];
        yield 'absolute login path' => [static fn() => new AuthSettings(loginPath: 'https://evil.example/login'), 'auth.login_path must be a local path'];
    }

    /**
     * @param callable(): AuthSettings $make
     */
    #[DataProvider('invalid')]
    public function test_bad_settings_fail_with_a_message_naming_the_key(callable $make, string $expected): void
    {
        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expected);
        $make();
    }
}
