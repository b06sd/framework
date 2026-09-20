<?php

declare(strict_types=1);

namespace Trunk\Router\Definition;

use Closure;
use Trunk\Router\Exception\InvalidRouteException;

/**
 * Loads a route file, which returns a function that defines routes:
 *
 *     RouteFile::load(__DIR__ . '/../routes/web.php')($routes);
 *
 * It says what is wrong when the file is missing or returns something else, instead of failing later
 * with "not callable".
 *
 * @api
 */
final class RouteFile
{
    /**
     * @throws InvalidRouteException
     */
    public static function load(string $path): Closure
    {
        if (!is_file($path)) {
            throw new InvalidRouteException(\sprintf('The route file "%s" does not exist.', basename($path)));
        }

        $define = (static fn(string $file): mixed => require $file)($path);

        return $define instanceof Closure ? $define : throw new InvalidRouteException(\sprintf('The route file "%s" must return a function: `return static function (RouteCollector $routes): void { ... };`.', basename($path)));
    }
}
