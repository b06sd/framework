<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Trunk\Foundation\Project\Project;
use Trunk\Foundation\Project\ProjectLoader;
use Trunk\Support\Directory;

/**
 * A throw-away project directory with a standard trunk.php, plus helpers to fake installed
 * Composer packages (vendor/composer/installed.json).
 */
final class CapabilityWorkspace
{
    public readonly string $base;

    /**
     * @param list<string> $modules
     */
    public function __construct(array $modules = ['Trunk\\Http\\HttpModule', 'Trunk\\Tests\\Fixtures\\Modules\\AlphaModule'])
    {
        $this->base = sys_get_temp_dir() . '/trunk-capability-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/config', 0o755, true);
        $this->manifest($modules);
    }

    /**
     * @param list<string> $modules
     */
    public function manifest(array $modules): void
    {
        $lines = implode("\n", array_map(static fn(string $m): string => '        \\' . $m . '::class,', $modules));
        file_put_contents($this->base . '/trunk.php', "<?php\n\ndeclare(strict_types=1);\n\n// The module list is this application's capability list.\nreturn [\n    'name' => 'demo',\n    'type' => 'web',\n    'modules' => [\n" . $lines . "\n    ],\n];\n");
    }

    public function project(): Project
    {
        return new ProjectLoader()->load($this->base);
    }

    /**
     * Fakes an installed package declaring a capability.
     *
     * @param array<string, mixed>  $capability the extra.trunk.capability metadata
     * @param array<string, string> $files      relative path => contents created inside the package
     */
    public function installPackage(string $name, array $capability, array $files = []): void
    {
        $directory = $this->base . '/vendor/' . $name;
        mkdir($directory, 0o755, true);

        foreach ($files as $path => $contents) {
            is_dir(\dirname($directory . '/' . $path)) || mkdir(\dirname($directory . '/' . $path), 0o755, true);
            file_put_contents($directory . '/' . $path, $contents);
        }

        $file = $this->base . '/vendor/composer/installed.json';
        is_dir(\dirname($file)) || mkdir(\dirname($file), 0o755, true);
        $installed = is_file($file) ? json_decode((string) file_get_contents($file), true) : ['packages' => []];
        $installed = \is_array($installed) ? $installed : ['packages' => []];
        $packages = \is_array($installed['packages'] ?? null) ? $installed['packages'] : [];
        $packages[] = ['name' => $name, 'install-path' => '../' . $name, 'extra' => ['trunk' => ['capability' => $capability]]];
        file_put_contents($file, json_encode(['packages' => $packages], \JSON_THROW_ON_ERROR));
    }

    /**
     * Records a plain Composer library (no Trunk metadata) as installed.
     */
    public function installLibrary(string $name): void
    {
        $file = $this->base . '/vendor/composer/installed.json';
        is_dir(\dirname($file)) || mkdir(\dirname($file), 0o755, true);
        $installed = is_file($file) ? json_decode((string) file_get_contents($file), true) : ['packages' => []];
        $installed = \is_array($installed) ? $installed : ['packages' => []];
        $packages = \is_array($installed['packages'] ?? null) ? $installed['packages'] : [];
        $packages[] = ['name' => $name, 'install-path' => '../' . $name];
        file_put_contents($file, json_encode(['packages' => $packages], \JSON_THROW_ON_ERROR));
    }

    public function cleanUp(): void
    {
        new Directory()->remove($this->base);
    }
}
