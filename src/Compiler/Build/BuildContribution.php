<?php

declare(strict_types=1);

namespace Trunk\Compiler\Build;

use Closure;

/**
 * @api
 */
final readonly class BuildContribution
{
    /**
     * @param list<string>                     $containerRoots ids the application resolves directly (controllers, middleware, commands)
     * @param list<Closure(string): void>      $writers        each receives the build directory to write into
     */
    public function __construct(
        public array $containerRoots = [],
        public array $writers = [],
    ) {}
}
