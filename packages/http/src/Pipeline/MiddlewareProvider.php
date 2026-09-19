<?php

declare(strict_types=1);

namespace Trunk\Http\Pipeline;

/**
 * Implemented by modules that contribute global middleware.
 *
 * @api
 */
interface MiddlewareProvider
{
    public function middleware(MiddlewareCollector $middleware): void;
}
