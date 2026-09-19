<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Foundation;

use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Manifest\ManifestCompiler;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Tests\Fixtures\Modules\AlphaModule;
use Trunk\Tests\Fixtures\Modules\BetaModule;

final class ManifestCompilerTest extends TestCase
{
    public function test_modules_are_collected_from_installed_packages_and_the_root_package(): void
    {
        // Arrange
        $dir = sys_get_temp_dir() . '/trunk-manifest-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/installed.json', json_encode(['packages' => [
            ['name' => 'acme/one', 'extra' => ['trunk' => ['modules' => [AlphaModule::class]]]],
            ['name' => 'acme/none'],
        ]], \JSON_THROW_ON_ERROR));
        file_put_contents($dir . '/composer.json', json_encode(
            ['extra' => ['trunk' => ['modules' => [BetaModule::class, AlphaModule::class]]]],
            \JSON_THROW_ON_ERROR,
        ));
        $compiler = new ManifestCompiler();

        // Act
        $modules = $compiler->compile($dir . '/installed.json', $dir . '/composer.json');
        $compiler->write($modules, $dir . '/manifest.php');
        $manifest = ModuleManifest::fromFile($dir . '/manifest.php');
        array_map(unlink(...), glob($dir . '/*') ?: []);
        rmdir($dir);

        // Assert
        self::assertSame([AlphaModule::class, BetaModule::class], $manifest->modules);
    }
}
