<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Trunk\Tusk\Loader\SourceTemplateLoader;
use Trunk\Tusk\Renderer;

/**
 * Creates a throw-away views directory from inline template sources.
 */
final class ViewHarness
{
    public readonly string $base;

    public readonly string $root;

    public readonly string $cache;

    /**
     * @param array<string, string> $templates name => Tusk source
     */
    public function __construct(array $templates = [])
    {
        $this->base = sys_get_temp_dir() . '/trunk-views-' . bin2hex(random_bytes(4));
        $this->root = $this->base . '/views';
        $this->cache = $this->base . '/cache';
        mkdir($this->root, 0o755, true);

        foreach ($templates as $name => $source) {
            $this->write($name, $source);
        }
    }

    public function write(string $name, string $source): void
    {
        $file = $this->root . '/' . $name . '.tusk.php';
        is_dir(\dirname($file)) || mkdir(\dirname($file), 0o755, true);
        file_put_contents($file, $source);
    }

    public function renderer(): Renderer
    {
        return new Renderer(new SourceTemplateLoader([$this->root], $this->cache));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $name, array $data = []): string
    {
        return $this->renderer()->render($name, $data);
    }

    public function cleanUp(): void
    {
        if (!is_dir($this->base)) {
            return;
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            if ($item instanceof SplFileInfo) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }

        rmdir($this->base);
    }
}
