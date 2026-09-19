<?php

declare(strict_types=1);

namespace Trunk\Contracts;

/**
 * Entry point run by a front controller once the Application is booted.
 * Deliberately minimal: HTTP handling is introduced in a later section.
 *
 * @api
 */
interface Kernel
{
    public function run(): void;
}
