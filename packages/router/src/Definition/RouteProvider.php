<?php

declare(strict_types=1);

namespace Trunk\Router\Definition;

/**
 * Implemented by modules that own routes. Called only when routes are collected (development or build).
 *
 * @api
 */
interface RouteProvider
{
    public function routes(RouteCollector $routes): void;
}
