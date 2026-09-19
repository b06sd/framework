<?php

declare(strict_types=1);

namespace Trunk\Tusk\Loader;

use Trunk\Support\FileWriter;
use Trunk\Tusk\Compiler\TemplateCompiler;
use Trunk\Tusk\Exception\TemplateNotFoundException;
use Trunk\Tusk\Runtime\CompiledTemplate;
use Trunk\Tusk\TemplateName;

/**
 * Development loader: compiles `.tusk.php` sources on demand into a cache directory. The cache
 * file name includes the source's path, mtime and size, so a stale compiled file is never served.
 */
final class SourceTemplateLoader implements TemplateLoader, SourceLocator
{
    /** @var array<string, CompiledTemplate> */
    private array $loaded = [];

    /**
     * @param list<string> $roots view directories, searched in order
     */
    public function __construct(
        private readonly array $roots,
        private readonly string $cacheDirectory,
        private readonly TemplateCompiler $compiler = new TemplateCompiler(),
        private readonly FileWriter $files = new FileWriter(),
    ) {}

    public function load(string $name): CompiledTemplate
    {
        if (isset($this->loaded[$name])) {
            return $this->loaded[$name];
        }

        $file = $this->locate($name);
        $cache = $this->cacheDirectory . '/' . sha1($file . '|' . filemtime($file) . '|' . filesize($file)) . '.php';

        if (!is_file($cache)) {
            if (!is_dir($this->cacheDirectory) && !mkdir($this->cacheDirectory, 0o755, true) && !is_dir($this->cacheDirectory)) {
                throw new TemplateNotFoundException(\sprintf('Unable to create the view cache directory "%s".', $this->cacheDirectory));
            }

            $this->files->write($cache, $this->compiler->compile((string) file_get_contents($file), $name)->php);
        }

        $template = require $cache;

        return $this->loaded[$name] = $template instanceof CompiledTemplate ? $template : throw new TemplateNotFoundException(\sprintf('The compiled file for "%s" is invalid.', $name));
    }

    public function sourcePath(string $name): ?string
    {
        try {
            return $this->locate($name);
        } catch (TemplateNotFoundException) {
            return null;
        }
    }

    private function locate(string $name): string
    {
        if (!TemplateName::isValid($name)) {
            throw new TemplateNotFoundException(\sprintf('"%s" is not a valid template name.', $name));
        }

        foreach ($this->roots as $root) {
            $realRoot = realpath($root);
            $real = $realRoot === false ? false : realpath($realRoot . '/' . $name . '.tusk.php');

            // The resolved path must stay inside the view root (blocks symlink escapes).
            if ($realRoot !== false && $real !== false && str_starts_with($real, $realRoot . \DIRECTORY_SEPARATOR) && is_file($real)) {
                return $real;
            }
        }

        throw new TemplateNotFoundException(\sprintf('Template "%s" was not found.', $name));
    }
}
