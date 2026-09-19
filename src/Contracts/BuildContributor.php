<?php

declare(strict_types=1);

namespace Trunk\Contracts;

use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Build\BuildContribution;

/**
 * Implemented by modules that own build artifacts (routes, views, commands...). `plan()` validates
 * and prepares but never writes; the BuildRunner writes everything only if every module succeeded.
 *
 * @api
 */
interface BuildContributor
{
    /**
     * @throws \Trunk\Compiler\Exception\CompilationException
     */
    public function plan(BuildContext $context): BuildContribution;
}
