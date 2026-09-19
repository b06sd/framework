<?php

declare(strict_types=1);

namespace Trunk\Compiler;

/**
 * A validated, not-yet-written container compilation: the generated source plus what it defines.
 */
final readonly class ContainerPlan
{
    /**
     * @param list<string> $boundIds       every service id the compiled container can resolve
     * @param list<string> $autoRegistered concrete classes that were wired automatically because something needed them
     */
    public function __construct(
        public string $source,
        public array $boundIds,
        public array $autoRegistered = [],
    ) {}
}
