<?php

declare(strict_types=1);

namespace Trunk\Error;

/**
 * Optional: a concrete fix for the developer, shown by the development error page and the CLI,
 * never in production responses.
 *
 * @api
 */
interface DeveloperHint
{
    public function hint(): ?string;
}
