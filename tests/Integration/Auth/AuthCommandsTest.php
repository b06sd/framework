<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use Trunk\Auth\Console\AuthPruneCommand;
use Trunk\Auth\Console\AuthTableCommand;
use Trunk\Auth\Console\AuthTables;
use Trunk\Auth\Console\AuthTokenCommand;
use Trunk\Auth\Session\ArraySessionStore;
use Trunk\Auth\Session\SessionRecord;
use Trunk\Auth\Settings\AuthSettings;
use Trunk\Auth\Settings\SessionSettings;
use Trunk\Auth\Settings\ThrottleSettings;
use Trunk\Auth\Settings\TokenSettings;
use Trunk\Auth\Throttle\AttemptCounter;
use Trunk\Auth\Throttle\LoginThrottle;
use Trunk\Auth\Token\DatabaseTokenStore;
use Trunk\Auth\User\DatabaseUserProvider;
use Trunk\Auth\User\UserProvider;
use Trunk\Console\Input\Input;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Runtime;
use Trunk\Support\Directory;
use Trunk\Tests\Support\AuthApp;
use Trunk\Tests\Support\OutputCapture;

final class AuthCommandsTest extends TestCase
{
    private ?string $base = null;

    private ?AuthApp $app = null;

    protected function tearDown(): void
    {
        $this->app?->cleanUp();

        if ($this->base !== null) {
            new Directory()->remove($this->base);
        }
    }

    public function test_auth_table_writes_one_valid_migration_and_never_overwrites(): void
    {
        // Arrange
        $this->base = sys_get_temp_dir() . '/trunk-authcmd-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0o755, true);
        $command = new AuthTableCommand(new Runtime(Environment::Local, false, $this->base), new AuthSettings());
        $capture = new OutputCapture();

        // Act
        $code = $command->handle(Input::fromArgv(['trunk', 'auth:table', '--users']), $capture->output);
        $files = glob($this->base . '/database/migrations/*_create_trunk_auth_tables.php') ?: [];
        $source = (string) file_get_contents($files[0] ?? '');

        // Assert
        self::assertSame(0, $code);
        self::assertCount(1, $files);
        self::assertStringContainsString("create('trunk_sessions'", $source);
        self::assertStringContainsString("create('trunk_tokens'", $source);
        self::assertStringContainsString("create('trunk_auth_throttles'", $source);
        self::assertStringContainsString("create('users'", $source);
        self::assertSame(0, self::lint($files[0]));
        $this->expectException(CommandFailedException::class);
        $command->handle(Input::fromArgv(['trunk', 'auth:table']), $capture->output);
    }

    public function test_the_migration_without_users_leaves_the_users_table_alone(): void
    {
        // Act
        $source = AuthTables::migration(new AuthSettings());

        // Assert
        self::assertStringNotContainsString("create('users'", $source);
        self::assertStringNotContainsString("dropIfExists('users')", $source);
    }

    public function test_auth_token_prints_a_working_token_once_and_refuses_unknown_users_and_bad_input(): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $id = $app->createUser('ada@example.com');
        $tokens = $app->tokens();
        $command = new AuthTokenCommand($this->provider($app), $tokens);
        $capture = new OutputCapture();

        // Act
        $code = $command->handle(Input::fromArgv(['trunk', 'auth:token', $id, 'ci', '--abilities=read,write:posts', '--ttl=600']), $capture->output);
        $out = $capture->stdout();
        preg_match('/trk_[A-Za-z0-9_-]{16}\.[A-Za-z0-9_-]{43}/', $out, $m);
        $plain = $m[0] ?? '';
        $verified = $tokens->verify($plain);

        // Assert
        self::assertSame(0, $code);
        self::assertSame($id, $verified?->userId);
        self::assertSame(['read', 'write:posts'], $verified->abilities);
        self::assertSame($verified->createdAt + 600, $verified->expiresAt);
        self::assertSame(1, substr_count($out, $plain), 'the token is printed exactly once');

        $refused = 0;

        foreach ([['999', 'ci', []], [$id, '', []], [$id, 'ci', ['--abilities=BAD ABILITY']], [$id, 'ci', ['--ttl=soon']]] as [$user, $name, $options]) {
            try {
                $command->handle(Input::fromArgv(['trunk', 'auth:token', $user, $name, ...$options]), new OutputCapture()->output);
            } catch (CommandFailedException) {
                ++$refused;
            }
        }

        self::assertSame(4, $refused);
    }

    public function test_auth_prune_removes_only_what_is_dead(): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $clock = $app->clock;
        $sessions = new ArraySessionStore();
        $sessionSettings = new SessionSettings(store: 'array', idleTimeout: 1200, lifetime: 3600, secure: false);
        $tokenSettings = new TokenSettings('trunk_tokens');
        $store = new DatabaseTokenStore($app->connection, $tokenSettings);
        $throttle = new LoginThrottle(new AttemptCounter($app->connection, $throttleSettings = new ThrottleSettings('trunk_auth_throttles', 3, 10, 600), $clock), $throttleSettings);
        $user = $app->user($app->createUser('ada@example.com'));
        $manager = $app->tokens();
        $old = $manager->issue($user, 'old', ['*'], 10);
        $forever = $manager->issue($user, 'forever', ['*'], 0);
        $clock->advance(700_000);
        $now = $clock->now();
        $sessions->write(hash('sha256', 'stale'), new SessionRecord([], $now - 5000, $now - 5000));
        $sessions->write(hash('sha256', 'live'), new SessionRecord([], $now - 100, $now - 10));
        $throttle->recordFailure('ada@example.com', '203.0.113.1');
        $clock->advance(601);
        $capture = new OutputCapture();

        // Act
        new AuthPruneCommand($sessions, $sessionSettings, $store, $throttle, $clock)->handle(Input::fromArgv(['trunk', 'auth:prune']), $capture->output);

        // Assert
        self::assertStringContainsString('Removed 1 session(s), 1 token(s) and 2 throttle counter(s).', $capture->stdout());
        self::assertNull($store->find($old->token->id));
        self::assertNotNull($store->find($forever->token->id), 'a token that never expires is kept');
        self::assertNotNull($sessions->read(hash('sha256', 'live')));
    }

    private function provider(AuthApp $app): UserProvider
    {
        return new DatabaseUserProvider($app->connection, new \Trunk\Auth\Settings\UserSettings());
    }

    private static function lint(string $file): int
    {
        exec(escapeshellarg(\PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);

        return $code;
    }
}
