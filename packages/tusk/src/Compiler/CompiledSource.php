<?php

declare(strict_types=1);

namespace Trunk\Tusk\Compiler;

final readonly class CompiledSource
{
    /**
     * @param list<string> $dependencies names of templates this one includes or uses as layout
     */
    public function __construct(public string $php, public array $dependencies) {}
}
