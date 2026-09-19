<?php

declare(strict_types=1);

namespace Trunk\Error;

/**
 * Implemented by exceptions that have a stable machine-readable code. It is an interface, not a
 * base class: Trunk never asks you to extend anything.
 *
 * @api
 */
interface HasErrorCode
{
    public function errorCode(): string;
}
