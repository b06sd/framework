<?php

declare(strict_types=1);

namespace Trunk\Compiler\Build;

use Trunk\Foundation\Configuration;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;

/**
 * @api
 */
final readonly class BuildContext
{
    public function __construct(
        public ModuleManifest $manifest,
        public Runtime $runtime,
        public Configuration $configuration,
    ) {}
}
