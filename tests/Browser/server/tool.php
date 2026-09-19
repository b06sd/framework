<?php

declare(strict_types=1);

// Helper commands for the browser tests. Usage: php tool.php <directory> <command> [args...]

require __DIR__ . '/../../../vendor/autoload.php';

use Trunk\Auth\Console\AuthTables;
use Trunk\Auth\Password\NativePasswordHasher;
use Trunk\Auth\Settings\AuthSettings;
use Trunk\Auth\Settings\PasswordSettings;
use Trunk\Auth\Settings\TokenSettings;
use Trunk\Auth\Settings\UserSettings;
use Trunk\Auth\Token\DatabaseTokenStore;
use Trunk\Auth\Token\TokenManager;
use Trunk\Auth\User\DatabaseUserProvider;
use Trunk\Database\Connection\ConnectionFactory;
use Trunk\Database\Schema\Schema;
use Trunk\Support\SystemClock;

/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];
[$directory, $command] = [$argv[1] ?? '', $argv[2] ?? ''];
$connection = new ConnectionFactory()->make('tool', ['driver' => 'sqlite', 'database' => $directory . '/app.sqlite']);

switch ($command) {
    case 'setup':
        $file = $directory . '/migration.php';
        file_put_contents($file, AuthTables::migration(new AuthSettings(), true));
        $migration = require $file;
        assert($migration instanceof \Trunk\Database\Migration\Migration);
        ($migration->up)(new Schema($connection));
        $hasher = new NativePasswordHasher(new PasswordSettings(memoryCost: 8192, timeCost: 1));
        $connection->table('users')->insert(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => $hasher->hash('correct horse battery'), 'session_version' => '1']);
        echo "ok\n";
        break;
    case 'age-sessions':
        $seconds = (int) ($argv[3] ?? 0);

        foreach ($connection->table('trunk_sessions')->get() as $row) {
            $payload = is_string($row['payload'] ?? null) ? $row['payload'] : '{}';
            $record = json_decode($payload, true, 32, \JSON_THROW_ON_ERROR);

            if (!is_array($record) || !is_int($record['a'] ?? null) || !is_int($record['c'] ?? null)) {
                continue;
            }

            $record['a'] -= $seconds;
            $record['c'] -= $seconds;
            $connection->table('trunk_sessions')->where('id_hash', '=', $row['id_hash'])->update(['payload' => json_encode($record, \JSON_THROW_ON_ERROR), 'last_activity' => $record['a']]);
        }

        echo "ok\n";
        break;
    case 'token':
        $user = new DatabaseUserProvider($connection, new UserSettings())->byId('1');
        $manager = new TokenManager(new DatabaseTokenStore($connection, new TokenSettings()), new TokenSettings(), new SystemClock());
        echo $manager->issue($user ?? throw new RuntimeException('no user'), 'browser', ['read'])->plainText, "\n";
        break;
    case 'session-count':
        echo $connection->table('trunk_sessions')->count(), "\n";
        break;
    default:
        fwrite(\STDERR, "unknown command\n");
        exit(1);
}
