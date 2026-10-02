<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Auth\Console\AuthTables;
use Trunk\Auth\Settings\AuthSettings;
use Trunk\Auth\Settings\LinkSettings;
use Trunk\Auth\Settings\UserSettings;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Connection\ConnectionFactory;
use Trunk\Database\Migration\Migration;
use Trunk\Database\Schema\Blueprint;
use Trunk\Database\Schema\Schema;

/**
 * `trunk auth:table --links` on a project whose users table predates email verification: the links
 * table is created and the verified column added, keeping every user. Runs on SQLite always, and on
 * MySQL / PostgreSQL when TRUNK_TEST_MYSQL_* / TRUNK_TEST_PGSQL_* are set. Touches only trunk_up_* tables.
 */
final class LinksUpgradeMigrationTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'mysql' => ['mysql'];
        yield 'pgsql' => ['pgsql'];
    }

    #[DataProvider('drivers')]
    public function test_the_links_migration_adds_the_table_and_the_verified_column_to_existing_users(string $driver): void
    {
        // Arrange: a users table from before, with a user in it
        $connection = $this->connection($driver);
        $schema = new Schema($connection);
        $settings = new AuthSettings(users: new UserSettings('trunk_up_users'), links: new LinkSettings('trunk_up_links'));
        $schema->dropIfExists('trunk_up_links');
        $schema->dropIfExists('trunk_up_users');
        $schema->create('trunk_up_users', function (Blueprint $t): void {
            $t->id();
            $t->string('email', 190)->unique();
            $t->string('password');
            $t->string('session_version', 64)->default('1');
        });
        $connection->table('trunk_up_users')->insert(['email' => 'ada@example.com', 'password' => 'x']);
        $file = sys_get_temp_dir() . '/trunk-up-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, AuthTables::linksMigration($settings));

        try {
            $migration = require $file;
            self::assertInstanceOf(Migration::class, $migration);

            // Act
            ($migration->up)($schema);

            // Assert
            self::assertTrue($schema->hasTable('trunk_up_links'));
            self::assertTrue($schema->hasColumn('trunk_up_users', 'email_verified_at'));
            self::assertSame(1, $connection->table('trunk_up_users')->whereNull('email_verified_at')->count(), 'existing users are kept, unverified');

            ($migration->down)($schema);
            self::assertFalse($schema->hasTable('trunk_up_links'));
        } finally {
            unlink($file);
            $schema->dropIfExists('trunk_up_links');
            $schema->dropIfExists('trunk_up_users');
        }
    }

    private function connection(string $driver): Connection
    {
        if ($driver === 'sqlite') {
            return new ConnectionFactory()->make('up', ['driver' => 'sqlite', 'database' => ':memory:']);
        }

        $prefix = 'TRUNK_TEST_' . ($driver === 'mysql' ? 'MYSQL' : 'PGSQL') . '_';
        $host = getenv($prefix . 'HOST');
        $database = getenv($prefix . 'DATABASE');

        if ($host === false || $database === false) {
            self::markTestSkipped('Set ' . $prefix . 'HOST and ' . $prefix . 'DATABASE to run this suite.');
        }

        return new ConnectionFactory()->make('up', ['driver' => $driver, 'host' => $host, 'database' => $database, 'username' => getenv($prefix . 'USER') ?: '', 'password' => getenv($prefix . 'PASSWORD') ?: '', 'port' => (int) (getenv($prefix . 'PORT') ?: ($driver === 'mysql' ? 3306 : 5432))]);
    }
}
