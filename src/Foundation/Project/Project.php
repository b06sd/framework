<?php

declare(strict_types=1);

namespace Trunk\Foundation\Project;

use Trunk\Contracts\Module;

/**
 * A Trunk application on disk: where it lives, what it is called, and which modules (capabilities)
 * it is made of. Read from `trunk.php`.
 */
final readonly class Project
{
    /**
     * @param list<class-string<Module>> $modules
     */
    public function __construct(
        public string $basePath,
        public string $name,
        public string $type,
        public array $modules,
    ) {}

    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->basePath : $this->basePath . '/' . ltrim($relative, '/');
    }

    public function configDirectory(): string
    {
        return $this->path('config');
    }

    public function buildDirectory(): string
    {
        return $this->path('build');
    }
}
