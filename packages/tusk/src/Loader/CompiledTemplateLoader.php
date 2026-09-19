<?php

declare(strict_types=1);

namespace Trunk\Tusk\Loader;

use Trunk\Compiler\ArtifactLoader;
use Trunk\Compiler\Exception\ArtifactException;
use Trunk\Tusk\Exception\TemplateNotFoundException;
use Trunk\Tusk\Runtime\CompiledTemplate;

/**
 * Production loader: reads the build manifest and requires precompiled files. No source access,
 * no directory scanning, no compilation.
 */
final class CompiledTemplateLoader implements TemplateLoader
{
    private ?ViewManifest $manifest = null;

    /** @var array<string, CompiledTemplate> */
    private array $loaded = [];

    public function __construct(private readonly string $buildDirectory) {}

    public function load(string $name): CompiledTemplate
    {
        if (isset($this->loaded[$name])) {
            return $this->loaded[$name];
        }

        $this->manifest ??= $this->readManifest();
        $file = $this->manifest->files[$name] ?? throw new TemplateNotFoundException(\sprintf('Template "%s" is not in the compiled build.', $name));
        try {
            return $this->loaded[$name] = ArtifactLoader::load($this->buildDirectory . '/' . $file, CompiledTemplate::class, 'template "' . $name . '"');
        } catch (ArtifactException $e) {
            throw new TemplateNotFoundException($e->getMessage(), previous: $e);
        }
    }

    private function readManifest(): ViewManifest
    {
        try {
            return ArtifactLoader::load($this->buildDirectory . '/views.php', ViewManifest::class, 'Tusk view manifest');
        } catch (ArtifactException $e) {
            throw new TemplateNotFoundException($e->getMessage(), previous: $e);
        }
    }
}
