<?php

declare(strict_types=1);

namespace Trunk\Router\Compiler;

use Trunk\Compiler\ArtifactLoader;
use Trunk\Compiler\Exception\ArtifactException;
use Trunk\Router\Definition\ArgumentPlanner;
use Trunk\Router\Exception\RouteCompilationException;

/**
 * The compiled, serializable form of all routes. Development and production share this shape;
 * production simply loads it from a PHP file that OPcache keeps in memory.
 *
 * @phpstan-import-type ArgumentPlan from ArgumentPlanner
 * @phpstan-type RouteEntry array{methods: list<string>, pattern: string, handler: array{string, string}, name: ?string, params: list<string>, args: list<ArgumentPlan>, middleware: list<string>}
 */
final readonly class RouteTable
{
    /**
     * @param array<string, array<string, int>> $static  method => path => route id
     * @param array<string, list<string>>       $dynamic method => regexes (each `(*MARK:id)` tagged)
     * @param list<RouteEntry>                  $routes
     * @param array<string, int>                $names   route name => route id
     */
    public function __construct(
        public array $static,
        public array $dynamic,
        public array $routes,
        public array $names,
    ) {}

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return array_values(array_unique([...array_map(\strval(...), array_keys($this->static)), ...array_map(\strval(...), array_keys($this->dynamic))]));
    }

    /**
     * Loads a table written by RouteArtifactWriter. The file returns a RouteTable instance, so a
     * production request pays for one `require` of an OPcache-resident file and no parsing or validation.
     *
     * @param non-empty-string $path explicit build artifact path
     */
    public static function fromFile(string $path): self
    {
        try {
            return ArtifactLoader::load($path, self::class, 'route table');
        } catch (ArtifactException $e) {
            throw new RouteCompilationException([$e->getMessage()], $e);
        }
    }
}
