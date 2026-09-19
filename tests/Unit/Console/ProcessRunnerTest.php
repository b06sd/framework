<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Console\Process\ProcessLauncher;
use Trunk\Console\Process\ProcessRunner;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Project\Project;

final class ProcessRunnerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/trunk-proc-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/public', 0o755, true);
        mkdir($this->base . '/vendor/bin', 0o755, true);
        touch($this->base . '/public/index.php');
        touch($this->base . '/vendor/bin/phpunit');
    }

    protected function tearDown(): void
    {
        new \Trunk\Support\Directory()->remove($this->base);
    }

    public function test_serve_builds_a_fixed_argument_array_with_the_development_environment(): void
    {
        // Arrange
        $launcher = new class implements ProcessLauncher {
            /** @var list<string> */
            public array $argv = [];

            /** @var array<string, string> */
            public array $environment = [];

            public function launch(array $argv, string $workingDirectory, array $environment): int
            {
                $this->argv = $argv;
                $this->environment = $environment;

                return 7;
            }
        };

        // Act
        $code = new ProcessRunner($launcher)->serve($this->project(), '127.0.0.1', 8080);

        // Assert
        self::assertSame(7, $code);
        self::assertSame([\PHP_BINARY, '-S', '127.0.0.1:8080', '-t', $this->base . '/public', $this->base . '/public/index.php'], $launcher->argv);
        self::assertSame('local', $launcher->environment['APP_ENV']);
        self::assertSame('1', $launcher->environment['APP_DEBUG']);
    }

    public function test_test_passes_arguments_to_phpunit_without_a_shell(): void
    {
        // Arrange
        $runner = new ProcessRunner();

        // Act
        $argv = $runner->testCommand($this->project(), ['--filter', 'Foo; rm -rf /']);

        // Assert
        self::assertSame([\PHP_BINARY, $this->base . '/vendor/bin/phpunit', '--filter', 'Foo; rm -rf /'], $argv);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function hostileEndpoints(): iterable
    {
        yield 'shell metacharacters' => ['127.0.0.1; rm -rf /', 8000];
        yield 'command substitution' => ['$(id)', 8000];
        yield 'backticks' => ['`id`', 8000];
        yield 'space' => ['a b', 8000];
        yield 'newline' => ["localhost\nfoo", 8000];
        yield 'empty host' => ['', 8000];
        yield 'port zero' => ['localhost', 0];
        yield 'port too large' => ['localhost', 70000];
    }

    #[DataProvider('hostileEndpoints')]
    public function test_serve_rejects_hosts_and_ports_that_are_not_plain_values(string $host, int $port): void
    {
        // Arrange
        $runner = new ProcessRunner();

        // Act & Assert
        $this->expectException(CommandFailedException::class);
        $runner->serveCommand($this->project(), $host, $port);
    }

    public function test_missing_entry_points_and_phpunit_are_explained(): void
    {
        // Arrange
        $runner = new ProcessRunner();
        $empty = new Project(sys_get_temp_dir() . '/trunk-nothing-' . bin2hex(random_bytes(3)), 'p', 'cli', []);
        $message = static function (callable $attempt): string {
            try {
                $attempt();
            } catch (CommandFailedException $e) {
                return $e->getMessage();
            }

            return '';
        };

        // Act
        $serve = $message(static fn() => $runner->serveCommand($empty, 'localhost', 8000));
        $test = $message(static fn() => $runner->testCommand($empty, []));

        // Assert
        self::assertStringContainsString('nothing to serve', $serve);
        self::assertStringContainsString('composer install', $test);
    }

    private function project(): Project
    {
        return new Project($this->base, 'p', 'api', []);
    }
}
