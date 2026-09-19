<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use LogicException;
use Trunk\Compiler\ContainerCompiler;
use Trunk\Container\CompiledContainer;

final class CompiledContainerLoader
{
    /**
     * Loads generated source and returns the class it defines.
     *
     * @return class-string<CompiledContainer>
     */
    public function load(string $source, string $className): string
    {
        $path = sys_get_temp_dir() . '/trunk-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($path, $source);
        require $path;
        unlink($path);

        $class = ContainerCompiler::NAMESPACE . '\\' . $className;

        return is_subclass_of($class, CompiledContainer::class) ? $class : throw new LogicException($class . ' was not generated.');
    }

    public function uniqueName(): string
    {
        return 'C' . bin2hex(random_bytes(6));
    }
}
