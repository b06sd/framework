<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use SplFileInfo;

/**
 * Reads the public API markers from source: a type tagged `@api` is the supported surface, one tagged
 * `@internal` (or carrying no tag) is not. Shared by the architecture test and `tools/api-list.php`.
 */
final class PublicApi
{
    /**
     * @return array<class-string, array{file: string, api: bool, internal: bool, kind: string}>
     */
    public function types(): array
    {
        $root = \dirname(__DIR__, 2);
        $types = [];
        $paths = [$root . '/src', ...(glob($root . '/packages/*/src', \GLOB_ONLYDIR) ?: [])];

        foreach ($paths as $path) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (preg_match('/^namespace ([^;]+);/m', $source, $ns) !== 1 || preg_match('/^(?:final |abstract |readonly )*(class|interface|enum|trait) (\w+)/m', $source, $decl) !== 1) {
                    continue;
                }

                /** @var class-string $class */
                $class = $ns[1] . '\\' . $decl[2];
                $doc = new ReflectionClass($class)->getDocComment() ?: '';
                $types[$class] = [
                    'file' => str_replace($root . '/', '', $file->getPathname()),
                    'api' => self::has($doc, 'api'),
                    'internal' => self::has($doc, 'internal'),
                    'kind' => $decl[1],
                ];
            }
        }

        ksort($types);

        return $types;
    }

    /**
     * Trunk types that a public, non-`@internal` method (or public property) of `$class` exposes in
     * its signature.
     *
     * @param class-string $class
     *
     * @return list<string> "Class::method(): Trunk\Type" descriptions
     */
    public function exposedTypes(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $found = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class || self::has($method->getDocComment() ?: '', 'internal')) {
                continue;
            }

            foreach ([...array_map(static fn($p): ?ReflectionType => $p->getType(), $method->getParameters()), $method->getReturnType()] as $type) {
                foreach (self::names($type) as $name) {
                    $found[] = $reflection->getShortName() . '::' . $method->getName() . '() uses ' . $name;
                }
            }
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            foreach (self::names($property->getType()) as $name) {
                $found[] = $reflection->getShortName() . '::$' . $property->getName() . ' is ' . $name;
            }
        }

        return $found;
    }

    /**
     * @return list<string> Trunk class names in a type
     */
    private static function names(?ReflectionType $type): array
    {
        $names = [];

        foreach ($type instanceof ReflectionUnionType ? $type->getTypes() : [$type] as $part) {
            if ($part instanceof ReflectionNamedType && !$part->isBuiltin() && str_starts_with($part->getName(), 'Trunk\\')) {
                $names[] = $part->getName();
            }
        }

        return $names;
    }

    private static function has(string $doc, string $tag): bool
    {
        return preg_match('/^\s*\*?\s*@' . $tag . '\b/m', $doc) === 1 || preg_match('/@' . $tag . '\b/', $doc) === 1;
    }
}
