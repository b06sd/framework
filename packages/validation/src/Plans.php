<?php

declare(strict_types=1);

namespace Trunk\Validation;

use Trunk\Validation\Plan\Plan;

/**
 * Where the engine finds the plan of a request class.
 */
interface Plans
{
    /**
     * @param class-string $class
     */
    public function plan(string $class): Plan;
}
