<?php

declare(strict_types=1);

namespace Trunk\Container;

use ReflectionClass;
use Trunk\Support\ClassName;

/**
 * Turns an unresolvable id into an actionable message: what it is, and how to fix it.
 */
final readonly class ResolutionHints
{
    /**
     * @param list<string> $path ids from the root of the resolution to the failing id
     */
    public function unresolved(string $id, array $path = [], ?string $neededBy = null): string
    {
        $message = \sprintf('Cannot resolve "%s"', $id);

        if ($neededBy !== null) {
            $message .= \sprintf(' (needed by %s)', $neededBy);
        }

        $message .= ': ' . $this->reason($id) . '.';

        if (\count($path) > 1) {
            $message .= ' Path: ' . implode(' -> ', $path) . '.';
        }

        return $message . ' Fix: ' . $this->fix($id);
    }

    public function isConcrete(string $id): bool
    {
        return ClassName::isValid($id) && class_exists($id) && new ReflectionClass($id)->isInstantiable();
    }

    private function reason(string $id): string
    {
        if (ClassName::isValid($id) && interface_exists($id)) {
            return 'it is an interface with no binding';
        }

        if (ClassName::isValid($id) && class_exists($id)) {
            return 'it is an abstract or otherwise non-instantiable class with no binding';
        }

        return 'it is not a class and has no binding';
    }

    private function fix(string $id): string
    {
        if (ClassName::isValid($id) && (interface_exists($id) || class_exists($id))) {
            return \sprintf('register an implementation in a module: $builder->bind(%s::class, YourImplementation::class);', $id);
        }

        return \sprintf('register it in a module with $builder->service(\'%s\', SomeClass::class), ->factory() or ->instance().', $id);
    }
}
