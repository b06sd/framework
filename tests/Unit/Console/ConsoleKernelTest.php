<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Trunk\Console\ConsoleKernel;
use Trunk\Contracts\Console\Command;
use Trunk\Tests\Fixtures\Console\BoomCommand;
use Trunk\Tests\Fixtures\Console\EchoCommand;
use Trunk\Tests\Support\OutputCapture;

final class ConsoleKernelTest extends TestCase
{
    private OutputCapture $capture;

    protected function setUp(): void
    {
        $this->capture = new OutputCapture();
    }

    public function test_a_command_is_run_with_its_arguments_and_options(): void
    {
        // Arrange
        $kernel = $this->kernel();

        // Act
        $code = $kernel->handle(['trunk', 'demo:echo', 'hi', '--loud']);

        // Assert
        self::assertSame(0, $code);
        self::assertSame("HI\n", $this->capture->stdout());
    }

    public function test_application_commands_are_available_alongside_built_ins(): void
    {
        // Arrange
        $kernel = $this->kernel();

        // Act
        $code = $kernel->handle(['trunk', 'demo:boom']);

        // Assert
        self::assertSame(1, $code);
        self::assertStringContainsString('it broke', $this->capture->stderr());
        self::assertStringContainsString('-v', $this->capture->stderr());
        self::assertStringNotContainsString('#0', $this->capture->stderr());
    }

    public function test_verbose_failures_include_the_trace(): void
    {
        // Arrange
        $kernel = $this->kernel();

        // Act
        $kernel->handle(['trunk', 'demo:boom', '-v']);

        // Assert
        self::assertStringContainsString('#0', $this->capture->stderr());
    }

    public function test_usage_mistakes_exit_with_code_two_and_suggest_the_closest_command(): void
    {
        // Arrange
        $kernel = $this->kernel();

        // Act
        $unknown = $kernel->handle(['trunk', 'demo:ecoh']);
        $missing = $kernel->handle(['trunk', 'demo:echo']);

        // Assert
        self::assertSame([2, 2], [$unknown, $missing]);
        self::assertStringContainsString('Did you mean "demo:echo"?', $this->capture->stderr());
        self::assertStringContainsString('Missing argument <text>. Usage: trunk demo:echo <text>', $this->capture->stderr());
    }

    public function test_list_help_and_version_describe_the_available_commands(): void
    {
        // Arrange
        $kernel = $this->kernel();

        // Act
        $kernel->handle(['trunk']);
        $list = $this->capture->stdout();
        $kernel->handle(['trunk', 'help', 'demo:echo']);
        $kernel->handle(['trunk', '--version']);

        // Assert
        self::assertStringContainsString('demo:echo', $list);
        self::assertStringContainsString('demo:boom', $list);
        self::assertStringContainsString('Usage: trunk demo:echo <text>', $this->capture->stdout());
        self::assertStringContainsString('--loud', $this->capture->stdout());
        self::assertStringContainsString('trunk ' . ConsoleKernel::VERSION, $this->capture->stdout());
    }

    public function test_a_failing_application_boot_does_not_break_listing_built_in_commands(): void
    {
        // Arrange
        $kernel = new ConsoleKernel(
            ['demo:echo' => static fn(): Command => new EchoCommand()],
            static fn(): array => throw new RuntimeException('config is broken'),
            $this->capture->output,
        );

        // Act
        $code = $kernel->handle(['trunk', 'list']);

        // Assert
        self::assertSame(0, $code);
        self::assertStringContainsString('demo:echo', $this->capture->stdout());
        self::assertStringContainsString('Application commands are unavailable: config is broken', $this->capture->stdout());
    }

    private function kernel(bool $withApplicationCommands = true): ConsoleKernel
    {
        return new ConsoleKernel(
            ['demo:echo' => static fn(): Command => new EchoCommand()],
            $withApplicationCommands ? static fn(): array => ['demo:boom' => new BoomCommand()] : null,
            $this->capture->output,
        );
    }
}
