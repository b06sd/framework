<?php

declare(strict_types=1);

namespace Trunk\Compiler;

use Trunk\Compiler\Exception\ArtifactException;

/**
 * The one place a generated build artifact is loaded: a single `require` of an OPcache-resident file
 * (no parsing, no validation on the hot path) whose result is checked to be the type the build writes.
 */
final class ArtifactLoader
{
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param non-empty-string $label what the artifact is, for the message (e.g. "ORM")
     *
     * @return T
     */
    public static function load(string $path, string $class, string $label): object
    {
        if (!is_file($path)) {
            throw new ArtifactException(\sprintf('The %s has not been built for production. Run `trunk build` first.', $label));
        }

        $artifact = (static fn(string $file): mixed => require $file)($path);

        return $artifact instanceof $class ? $artifact : throw new ArtifactException(\sprintf('The %s build is not valid (%s). Run `trunk build` again.', $label, basename($path)));
    }
}
