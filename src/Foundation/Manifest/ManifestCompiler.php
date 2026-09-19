<?php

declare(strict_types=1);

namespace Trunk\Foundation\Manifest;

use JsonException;
use Trunk\Foundation\Exception\ConfigurationException;
use Trunk\Support\FileWriter;

/**
 * Build-time only: reads `extra.trunk.modules` from installed packages (and optionally the
 * root package) and writes a plain PHP manifest. Never used on the request path.
 */
final class ManifestCompiler
{
    /**
     * @return list<string>
     */
    public function compile(string $installedJsonPath, ?string $rootComposerJsonPath = null): array
    {
        $installed = $this->readJson($installedJsonPath);
        $packages = $installed['packages'] ?? $installed;
        $modules = [];

        if (\is_array($packages)) {
            foreach ($packages as $package) {
                $modules = [...$modules, ...$this->modulesOf($package)];
            }
        }

        if ($rootComposerJsonPath !== null) {
            $modules = [...$modules, ...$this->modulesOf($this->readJson($rootComposerJsonPath))];
        }

        return array_values(array_unique($modules));
    }

    /**
     * @param list<string> $modules
     */
    public function write(array $modules, string $targetPath): void
    {
        $code = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($modules, true) . ";\n";
        new FileWriter()->write($targetPath, $code);
    }

    /**
     * @return list<string>
     */
    private function modulesOf(mixed $package): array
    {
        if (!\is_array($package)) {
            return [];
        }

        $extra = $package['extra'] ?? null;
        $trunk = \is_array($extra) ? ($extra['trunk'] ?? null) : null;
        $modules = \is_array($trunk) ? ($trunk['modules'] ?? null) : null;

        if (!\is_array($modules)) {
            return [];
        }

        return array_values(array_filter($modules, \is_string(...)));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readJson(string $path): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new ConfigurationException(\sprintf('Unable to read "%s".', $path));
        }

        try {
            $data = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ConfigurationException(\sprintf('"%s" is not valid JSON.', $path), 0, $e);
        }

        return \is_array($data) ? $data : throw new ConfigurationException(\sprintf('"%s" must contain a JSON object.', $path));
    }
}
