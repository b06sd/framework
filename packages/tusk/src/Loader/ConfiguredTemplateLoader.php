<?php

declare(strict_types=1);

namespace Trunk\Tusk\Loader;

use Trunk\Foundation\Configuration;
use Trunk\Tusk\Exception\TemplateNotFoundException;
use Trunk\Tusk\Runtime\CompiledTemplate;

/**
 * Chooses the loader from configuration, explicitly:
 *   views.mode = "development" -> views.paths (list) and views.cache (dir)
 *   views.mode = "compiled"    -> views.build (dir with views.php)
 */
final class ConfiguredTemplateLoader implements TemplateLoader, SourceLocator
{
    private ?TemplateLoader $inner = null;

    public function __construct(private readonly Configuration $configuration) {}

    public function load(string $name): CompiledTemplate
    {
        $this->inner ??= $this->create();

        return $this->inner->load($name);
    }

    public function sourcePath(string $name): ?string
    {
        $this->inner ??= $this->create();

        return $this->inner instanceof SourceLocator ? $this->inner->sourcePath($name) : null;
    }

    private function create(): TemplateLoader
    {
        return match ($mode = $this->configuration->string('views.mode')) {
            'compiled' => new CompiledTemplateLoader($this->configuration->string('views.build')),
            'development' => new SourceTemplateLoader($this->paths(), $this->configuration->string('views.cache')),
            default => throw new TemplateNotFoundException(\sprintf('views.mode must be "development" or "compiled", "%s" given.', $mode)),
        };
    }

    /**
     * @return list<string>
     */
    private function paths(): array
    {
        $paths = array_values($this->configuration->array('views.paths'));

        return array_values(array_filter($paths, is_string(...)));
    }
}
