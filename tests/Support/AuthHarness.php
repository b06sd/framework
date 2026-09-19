<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Trunk\Auth\Console\AuthTables;
use Trunk\Auth\Password\NativePasswordHasher;
use Trunk\Auth\Password\PasswordHasher;
use Trunk\Auth\Settings\AuthSettings;
use Trunk\Auth\Settings\PasswordSettings;
use Trunk\Auth\Settings\SessionSettings;
use Trunk\Auth\Settings\ThrottleSettings;
use Trunk\Auth\Settings\TokenSettings;
use Trunk\Auth\Settings\UserSettings;
use Trunk\Auth\User\DatabaseUser;
use Trunk\Auth\User\DatabaseUserProvider;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Connection\ConnectionFactory;
use Trunk\Database\Connection\QueryLog;
use Trunk\Database\Migration\Migration;
use Trunk\Database\Schema\Schema;

/**
 * A database with the auth tables (created by the real generated migration), on SQLite always and on
 * MySQL or PostgreSQL when TRUNK_TEST_MYSQL_* / TRUNK_TEST_PGSQL_* are set. It only creates and drops
 * tables named trunk_at_*.
 */
final class AuthHarness
{
    public readonly FixedClock $clock;

    public readonly PasswordHasher $hasher;

    public readonly DatabaseUserProvider $users;

    private function __construct(public readonly Connection $connection, public readonly AuthSettings $settings)
    {
        $this->clock = new FixedClock(1_800_000_000);
        $this->hasher = new NativePasswordHasher($settings->password);
        $this->users = new DatabaseUserProvider($connection, $settings->users);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'mysql' => ['mysql'];
        yield 'pgsql' => ['pgsql'];
    }

    /**
     */
    public static function for(string $driver, ?SessionSettings $session = null, ?QueryLog $log = null): ?self
    {
        $config = self::config($driver);

        if ($config === null) {
            return null;
        }

        $session ??= new SessionSettings(store: 'database', table: 'trunk_at_sessions', secure: false);
        $settings = new AuthSettings(
            new PasswordSettings(memoryCost: 8192, timeCost: 1, minLength: 12),
            new UserSettings('trunk_at_users'),
            $session,
            new TokenSettings('trunk_at_tokens'),
            new ThrottleSettings('trunk_at_throttles', 3, 10, 600, 10),
        );
        $harness = new self(new ConnectionFactory()->make('auth-test', $config, $log), $settings);
        $harness->tables(true);
        $log?->clear();

        return $harness;
    }

    public function createUser(string $email, string $password = 'correct horse battery', string $name = 'Test User', string $version = '1'): DatabaseUser
    {
        $this->connection->table('trunk_at_users')->insert(['name' => $name, 'email' => $email, 'password' => $this->hasher->hash($password), 'session_version' => $version]);
        $user = $this->users->byIdentifier($email);
        \assert($user instanceof DatabaseUser);

        return $user;
    }

    public function cleanUp(): void
    {
        $this->tables(false);
        $this->connection->disconnect();
    }

    private function tables(bool $create): void
    {
        $schema = new Schema($this->connection);

        foreach (['trunk_at_users', 'trunk_at_throttles', 'trunk_at_tokens', 'trunk_at_sessions'] as $table) {
            $schema->dropIfExists($table);
        }

        if (!$create) {
            return;
        }

        $file = sys_get_temp_dir() . '/trunk-at-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, AuthTables::migration($this->settings, true));
        $migration = require $file;
        unlink($file);
        \assert($migration instanceof Migration);
        ($migration->up)($schema);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function config(string $driver): ?array
    {
        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => ':memory:'];
        }

        $prefix = 'TRUNK_TEST_' . ($driver === 'mysql' ? 'MYSQL' : 'PGSQL') . '_';
        $host = getenv($prefix . 'HOST');
        $database = getenv($prefix . 'DATABASE');

        if ($host === false || $database === false) {
            return null;
        }

        return ['driver' => $driver, 'host' => $host, 'database' => $database, 'username' => getenv($prefix . 'USER') ?: '', 'password' => getenv($prefix . 'PASSWORD') ?: '', 'port' => (int) (getenv($prefix . 'PORT') ?: ($driver === 'mysql' ? 3306 : 5432))];
    }
}
