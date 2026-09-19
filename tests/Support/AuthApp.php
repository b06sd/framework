<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Trunk\Auth\AuthModule;
use Trunk\Auth\Console\AuthTables;
use Trunk\Auth\Settings\AuthSettings;
use Trunk\Auth\Settings\TokenSettings;
use Trunk\Auth\Settings\UserSettings;
use Trunk\Auth\Token\DatabaseTokenStore;
use Trunk\Auth\Token\TokenManager;
use Trunk\Auth\User\DatabaseUser;
use Trunk\Auth\User\DatabaseUserProvider;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Connection\ConnectionFactory;
use Trunk\Database\DatabaseModule;
use Trunk\Database\Migration\Migration;
use Trunk\Database\Schema\Schema;
use Trunk\Foundation\Diagnostics\DiagnosticsModule;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Logging\LoggingModule;
use Trunk\Http\HttpModule;
use Trunk\Http\Kernel\HttpKernel;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Auth\AuthTestModule;

/**
 * A complete application (real kernel, real container, SQLite file database) with the auth capability
 * and the auth test routes, in development or compiled mode.
 */
final class AuthApp
{
    public readonly Connection $connection;

    public readonly KernelHarness $harness;

    public readonly FixedClock $clock;

    private readonly string $directory;

    /**
     * @param array<string, mixed> $auth       overrides for the `auth` configuration
     * @param list<class-string<\Trunk\Contracts\Module>> $extraModules
     * @param array<string, mixed> $logging    overrides for the `logging` configuration
     */
    public function __construct(array $auth = [], array $extraModules = [], array $logging = [])
    {
        $this->clock = new FixedClock(time());
        $this->directory = sys_get_temp_dir() . '/trunk-authapp-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0o755, true);
        $database = $this->directory . '/app.sqlite';
        $auth = array_replace_recursive(['password' => ['memory_cost' => 8192, 'time_cost' => 1], 'session' => ['secure' => false, 'store' => 'database']], $auth);
        $this->connection = new ConnectionFactory()->make('setup', ['driver' => 'sqlite', 'database' => $database]);
        $file = $this->directory . '/migration.php';
        file_put_contents($file, AuthTables::migration(AuthSettings::fromConfiguration(new \Trunk\Foundation\Configuration(['auth' => $auth])), true));
        $migration = require $file;
        \assert($migration instanceof Migration);
        ($migration->up)(new Schema($this->connection));
        $this->harness = new KernelHarness(
            modules: [LoggingModule::class, DiagnosticsModule::class, DatabaseModule::class, HttpModule::class, AuthModule::class, AuthTestModule::class, ...$extraModules],
            configuration: [
                'logging' => [...['channel' => 'null', 'level' => 'error'], ...$logging],
                'database' => ['default' => 'main', 'log_queries' => false, 'migrations' => $this->directory, 'connections' => ['main' => ['driver' => 'sqlite', 'database' => $database]]],
                'auth' => $auth,
            ],
        );
    }

    public function kernel(string $mode): HttpKernel
    {
        return $mode === 'compiled' ? $this->harness->compiled(Environment::Production) : $this->harness->development(Environment::Production);
    }

    public function client(string $mode, string $address = '203.0.113.7'): AuthClient
    {
        return new AuthClient($this->kernel($mode), $address);
    }

    public function tokens(int $ttl = 3600, int $touchInterval = 300): TokenManager
    {
        return new TokenManager(new DatabaseTokenStore($this->connection, new TokenSettings('trunk_tokens')), new TokenSettings('trunk_tokens', $ttl, $touchInterval), $this->clock);
    }

    public function user(string $id): DatabaseUser
    {
        $user = new DatabaseUserProvider($this->connection, new UserSettings())->byId($id);
        \assert($user instanceof DatabaseUser);

        return $user;
    }

    public function createUser(string $email, string $password = 'correct horse battery', string $version = '1'): string
    {
        $hasher = new \Trunk\Auth\Password\NativePasswordHasher(new \Trunk\Auth\Settings\PasswordSettings(memoryCost: 8192, timeCost: 1));

        return (string) $this->connection->table('users')->insertGetId(['name' => 'Test', 'email' => $email, 'password' => $hasher->hash($password), 'session_version' => $version]);
    }

    public function cleanUp(): void
    {
        $this->connection->disconnect();
        $this->harness->cleanUp();
        new Directory()->remove($this->directory);
    }
}
