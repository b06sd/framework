<?php

declare(strict_types=1);

namespace Trunk\Tests\Security;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Trunk\Foundation\Exception\ConfigurationException;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Tests\Fixtures\Modules\NotAModule;
use Trunk\Tests\Support\ForbiddenConstructScanner;

final class CoreSecurityTest extends TestCase
{
    public function test_framework_source_contains_no_dangerous_constructs(): void
    {
        // Arrange
        $scanner = new ForbiddenConstructScanner();
        $violations = [];

        // Act
        foreach (['/../../src', '/../../packages'] as $root) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . $root, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                    $allowed = str_ends_with($file->getPathname(), 'packages/console/src/Process/ProcOpenLauncher.php') ? ['proc_open'] : [];
                    $violations = [...$violations, ...$scanner->scan((string) file_get_contents($file->getPathname()), $file->getFilename(), $allowed)];
                }
            }
        }

        // Assert
        self::assertSame([], $violations);
    }

    public function test_process_execution_exists_in_exactly_one_allowlisted_file(): void
    {
        // Arrange
        $scanner = new ForbiddenConstructScanner();
        $users = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../packages', RecursiveDirectoryIterator::SKIP_DOTS));

        // Act
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php' && $scanner->scan((string) file_get_contents($file->getPathname()), $file->getFilename()) !== []) {
                $users[] = $file->getFilename();
            }
        }

        // Assert
        self::assertSame(['ProcOpenLauncher.php'], $users);
    }

    public function test_manifest_never_autoloads_names_that_are_not_plain_class_names(): void
    {
        // Arrange
        $attempts = [];
        $autoloader = static function (string $class) use (&$attempts): void {
            $attempts[] = $class;
        };
        spl_autoload_register($autoloader);
        $hostile = ['Trunk\\..\\..\\Secret', '../../etc/passwd', "Foo'); system('id'); //", 'Trunk\\Tests\\Fixtures\\Modules\\NotAModule' . "\0"];
        $rejected = 0;

        // Act
        foreach ($hostile as $name) {
            try {
                new ModuleManifest([$name]);
            } catch (ConfigurationException) {
                ++$rejected;
            }
        }

        spl_autoload_unregister($autoloader);

        // Assert
        self::assertSame(\count($hostile), $rejected);
        self::assertSame([], $attempts);
    }

    public function test_manifest_rejects_classes_that_are_not_modules(): void
    {
        // Arrange
        $entries = [NotAModule::class];

        // Act & Assert
        $this->expectException(ConfigurationException::class);
        new ModuleManifest($entries);
    }

    public function test_manifest_rejects_unknown_and_non_string_entries(): void
    {
        // Arrange
        $entries = ['Does\\Not\\Exist', 42];

        // Act & Assert
        $this->expectException(ConfigurationException::class);
        new ModuleManifest($entries);
    }

    public function test_manifest_rejects_path_traversal_style_class_names_before_autoloading(): void
    {
        // Arrange
        $entries = ['Trunk\\..\\..\\etc\\passwd'];

        // Act & Assert
        $this->expectException(ConfigurationException::class);
        new ModuleManifest($entries);
    }
}
