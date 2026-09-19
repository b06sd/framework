<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Trunk\Console\Input\Input;
use Trunk\Tests\Support\OutputCapture;

final class InputOutputTest extends TestCase
{
    public function test_argv_is_split_into_command_arguments_options_and_flags(): void
    {
        // Arrange
        $argv = ['trunk', 'new', 'shop', '--type=web', '--force', '-v'];

        // Act
        $input = Input::fromArgv($argv);

        // Assert
        self::assertSame('new', $input->command);
        self::assertSame(['shop'], $input->arguments);
        self::assertSame('web', $input->option('type'));
        self::assertNull($input->option('missing'));
        self::assertSame('fallback', $input->option('missing', 'fallback'));
        self::assertTrue($input->flag('force'));
        self::assertTrue($input->verbose());
        self::assertFalse($input->wantsHelp());
    }

    public function test_everything_after_a_double_dash_is_passed_through_untouched(): void
    {
        // Arrange
        $argv = ['trunk', 'test', '--', '--filter=Foo', '-v'];

        // Act
        $input = Input::fromArgv($argv);

        // Assert
        self::assertSame(['--filter=Foo', '-v'], $input->arguments);
        self::assertFalse($input->verbose());
    }

    public function test_help_and_version_flags_are_recognised_in_long_and_short_form(): void
    {
        // Arrange

        // Act & Assert
        self::assertTrue(Input::fromArgv(['trunk', 'x', '-h'])->wantsHelp());
        self::assertTrue(Input::fromArgv(['trunk', 'x', '--help'])->wantsHelp());
        self::assertTrue(Input::fromArgv(['trunk', '--version'])->wantsVersion());
        self::assertNull(Input::fromArgv(['trunk'])->command);
    }

    public function test_output_writes_to_the_right_streams_and_aligns_tables_without_colour(): void
    {
        // Arrange
        $capture = new OutputCapture();

        // Act
        $capture->output->line('hello');
        $capture->output->success('done');
        $capture->output->error('bad');
        $capture->output->table(['Name', 'Value'], [['a', '1'], ['longer', '22']]);

        // Assert
        self::assertStringContainsString("hello\n✓ done\n", $capture->stdout());
        self::assertStringContainsString("Name    Value\n------  -----\na       1\nlonger  22\n", $capture->stdout());
        self::assertSame("bad\n", $capture->stderr());
        self::assertStringNotContainsString("\033", $capture->stdout());
    }
}
