<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * A command whose dependency has gone (here: a capability was removed) reports itself and leaves every
 * other command, and the "no such command" message, working.
 */
final class BrokenCommandEndToEndTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    public function test_removing_a_capability_a_command_needs_does_not_break_the_console(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('repro', 'web');
        $project->trunk(['package:install', 'console']);
        $project->trunk(['package:install', 'database']);
        $project->trunk(['package:install', 'auth']);
        $project->trunk(['make:command', 'CreateUser']);
        $command = (string) file_get_contents($project->directory . '/app/Commands/CreateUser.php');
        file_put_contents($project->directory . '/app/Commands/CreateUser.php', str_replace("final class CreateUser implements Command\n{", "final class CreateUser implements Command\n{\n    public function __construct(private \\Trunk\\Auth\\Password\\PasswordHasher \$hasher) {}\n", $command));
        $module = (string) file_get_contents($project->directory . '/app/AppModule.php');
        file_put_contents($project->directory . '/app/AppModule.php', (string) preg_replace('/\}\s*$/', '', str_replace("RouteProvider\n{", "RouteProvider, \\Trunk\\Contracts\\Console\\CommandProvider\n{", $module)) . "\n    public function commands(\\Trunk\\Contracts\\Console\\CommandCollector \$commands): void\n    {\n        \$commands->add(\\App\\Commands\\CreateUser::class);\n    }\n}\n");
        [$before] = $project->trunk(['nope']);
        $project->trunk(['package:remove', 'auth']);

        // Act
        [$unknown, , $unknownErr] = $project->trunk(['nope']);
        [$status, $statusOut] = $project->trunk(['migrate:status']);
        [$routes] = $project->trunk(['route:list']);
        [$list, $listOut] = $project->trunk(['list']);

        // Assert
        self::assertSame(2, $before);
        self::assertSame(2, $unknown, $unknownErr);
        self::assertStringContainsString('There is no command "nope".', $unknownErr);
        self::assertStringContainsString('Note: App\Commands\CreateUser could not be loaded: Cannot resolve "Trunk\Auth\Password\PasswordHasher"', $unknownErr);
        self::assertSame(0, $status, 'migrate:status must not depend on CreateUser: ' . $statusOut);
        self::assertSame(0, $routes);
        self::assertSame(0, $list);
        self::assertStringContainsString('CreateUser could not be loaded', $listOut);
    }
}
