<?php

declare(strict_types=1);

namespace Trunk\Tusk\Loader;

/**
 * A loader that reads template sources from disk and can say where one lives (development only;
 * a compiled build has no sources).
 */
interface SourceLocator
{
    public function sourcePath(string $name): ?string;
}
