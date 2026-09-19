<?php

declare(strict_types=1);

namespace Trunk\Compiler\Build;

final readonly class BuildReport
{
    /**
     * @param list<string> $contributors module classes that produced artifacts
     * @param list<string> $autoRegistered classes wired automatically because something needed them
     */
    public function __construct(
        public array $contributors,
        public array $autoRegistered,
    ) {}
}
