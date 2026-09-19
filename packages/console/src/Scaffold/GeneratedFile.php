<?php

declare(strict_types=1);

namespace Trunk\Console\Scaffold;

final readonly class GeneratedFile
{
    public function __construct(
        public string $path,
        public string $hint,
    ) {}
}
