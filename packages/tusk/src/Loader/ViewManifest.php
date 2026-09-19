<?php

declare(strict_types=1);

namespace Trunk\Tusk\Loader;

/**
 * Maps template names to compiled files (relative to the build directory). `views.php` returns an
 * instance of this class, so loading it costs one `require` and no validation.
 */
final readonly class ViewManifest
{
    /**
     * @param array<string, string> $files
     */
    public function __construct(public array $files) {}
}
