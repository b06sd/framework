<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Trunk\Console\Commands\ServeCommand;
use Trunk\Console\Input\Input;
use Trunk\Console\Process\ProcessRunner;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Project\Project;
use Trunk\Support\Directory;
use Trunk\Tests\Support\OutputCapture;
use Trunk\Tests\Support\RecordingLauncher;

final class ServeCommandTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/trunk-serve-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/public', 0o755, true);
        touch($this->base . '/public/index.php');
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->base);
    }

    public function test_the_default_port_is_8006(): void
    {
        // Arrange

        // Act
        [$address, $printed] = $this->serve([]);

        // Assert
        self::assertSame('127.0.0.1:8006', $address);
        self::assertStringContainsString('http://127.0.0.1:8006', $printed);
        self::assertSame('8006', ServeCommand::DEFAULT_PORT);
    }

    public function test_the_port_comes_from_the_option_first_then_app_port_from_env_then_8006(): void
    {
        // Arrange

        // Act
        [$fromEnv] = $this->serve(['APP_PORT' => '9100']);
        [$fromOption] = $this->serve(['APP_PORT' => '9100'], '--port=9200', '--host=localhost');

        // Assert
        self::assertSame('127.0.0.1:9100', $fromEnv);
        self::assertSame('localhost:9200', $fromOption);
    }

    public function test_an_invalid_port_from_env_is_refused_before_anything_is_started(): void
    {
        // Arrange

        // Act & Assert
        $this->expectException(CommandFailedException::class);
        $this->serve(['APP_PORT' => '80; touch /tmp/x']);
    }

    /**
     * @param array<string, string> $variables
     *
     * @return array{string, string} the host:port argument and what was printed
     */
    private function serve(array $variables, string ...$arguments): array
    {
        $launcher = new RecordingLauncher();
        $capture = new OutputCapture();
        $command = new ServeCommand(new Project($this->base, 'p', 'api', []), new ProcessRunner($launcher), $variables);

        $command->handle(Input::fromArgv(['trunk', 'serve', ...array_values($arguments)]), $capture->output);

        return [$launcher->argv[2] ?? '', $capture->stdout()];
    }
}
