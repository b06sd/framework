<?php

declare(strict_types=1);

namespace Trunk\Tests\Security;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Trunk\Console\Scaffold\Profile;
use Trunk\Console\Scaffold\ProjectScaffolder;
use Trunk\Support\Directory;
use Trunk\Tests\Support\ForbiddenConstructScanner;
use Trunk\Tests\Support\ScaffoldedProject;

final class ConsoleSecurityTest extends TestCase
{
    public function test_generated_projects_contain_no_dangerous_constructs_in_any_profile(): void
    {
        // Arrange
        $base = sys_get_temp_dir() . '/trunk-sec-' . bin2hex(random_bytes(4));
        mkdir($base);
        $scanner = new ForbiddenConstructScanner();
        $violations = [];

        // Act
        foreach (Profile::cases() as $profile) {
            $target = $base . '/' . $profile->value;
            new ProjectScaffolder()->create('sample', $profile, $target);
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file instanceof SplFileInfo && $file->getExtension() === 'php' && !str_ends_with($file->getFilename(), '.tusk.php')) {
                    $violations = [...$violations, ...$scanner->scan((string) file_get_contents($file->getPathname()), $profile->value . '/' . $file->getFilename())];
                }
            }
        }

        new Directory()->remove($base);

        // Assert
        self::assertSame([], $violations);
    }

    public function test_scaffolding_cannot_be_steered_outside_the_working_directory_by_a_hostile_name(): void
    {
        // Arrange
        $project = new ScaffoldedProject('probe', 'api');
        $before = glob($project->base . '/*') ?: [];

        // Act
        [$code] = $project->trunk(['new', '../escaped', '--type=api'], $project->base);
        [$absoluteCode] = $project->trunk(['new', '/tmp/trunk-absolute-escape', '--type=api'], $project->base);
        $after = glob($project->base . '/*') ?: [];
        $escaped = file_exists(\dirname($project->base) . '/escaped') || file_exists('/tmp/trunk-absolute-escape');
        $project->cleanUp();

        // Assert
        self::assertSame([1, 1], [$code, $absoluteCode]);
        self::assertSame($before, $after);
        self::assertFalse($escaped);
    }

    public function test_the_serve_command_refuses_hostile_hosts_end_to_end(): void
    {
        // Arrange
        $project = new ScaffoldedProject('probe', 'api');

        // Act
        [$code, , $error] = $project->trunk(['serve', '--host=127.0.0.1;touch /tmp/trunk-pwned']);
        $project->cleanUp();

        // Assert
        self::assertSame(1, $code);
        self::assertStringContainsString('not a valid host', $error);
        self::assertFileDoesNotExist('/tmp/trunk-pwned');
    }

    public function test_a_module_that_is_not_installed_is_reported_instead_of_being_autoloaded_blindly(): void
    {
        // Arrange
        $project = new ScaffoldedProject('probe', 'api');
        file_put_contents($project->directory . '/trunk.php', "<?php\nreturn ['name' => 'probe', 'type' => 'api', 'modules' => ['../../evil/Module']];\n");

        // Act
        [$code, , $error] = $project->trunk(['doctor']);
        [$helpCode, $help] = $project->trunk(['help']);
        $project->cleanUp();

        // Assert
        self::assertSame(1, $code);
        self::assertStringContainsString('trunk.php', $error);
        self::assertSame(0, $helpCode);
        self::assertStringContainsString('new', $help);
    }
}
