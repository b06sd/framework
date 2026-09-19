<?php

declare(strict_types=1);

namespace Trunk\Router\Url;

use Trunk\Router\Compiler\RouteTable;
use Trunk\Router\Exception\InvalidRouteException;
use Trunk\Router\Exception\RouteNotFoundException;
use Trunk\Router\Pattern\Constraints;
use Trunk\Router\Pattern\PatternParser;

/**
 * Builds URLs for named routes. Values are validated against the route's constraints and percent-encoded.
 *
 * @api
 */
final readonly class UrlGenerator
{
    /** @internal wired by the container, not part of the API */
    public function __construct(
        private RouteTable $table,
        private PatternParser $parser = new PatternParser(),
        private Constraints $constraints = new Constraints(),
    ) {}

    /**
     * @param array<string, string|int> $params values for route parameters; extras become query parameters
     * @param array<string, mixed>      $query
     */
    public function generate(string $name, array $params = [], array $query = []): string
    {
        $id = $this->table->names[$name] ?? throw new RouteNotFoundException(\sprintf('There is no route named "%s".', $name));
        $pattern = $this->table->routes[$id]['pattern'];
        $path = '';

        foreach ($this->parser->parse($pattern)->segments as $segment) {
            if ($segment->param === null) {
                $path .= '/' . $segment->literal;

                continue;
            }

            if (!isset($params[$segment->param])) {
                if ($segment->optional) {
                    break;
                }

                throw new InvalidRouteException(\sprintf('Route "%s" requires the parameter "%s".', $name, $segment->param));
            }

            $value = (string) $params[$segment->param];
            unset($params[$segment->param]);
            $encoded = $this->constraints->isCatchAll((string) $segment->regex)
                ? implode('/', array_map(\rawurlencode(...), explode('/', $value)))
                : rawurlencode($value);

            // Constraints apply to the encoded segment, exactly as the matcher sees it.
            if (preg_match('~^(?:' . $segment->regex . ')$~D', $encoded) !== 1) {
                throw new InvalidRouteException(\sprintf('The value for parameter "%s" of route "%s" does not satisfy its constraint.', $segment->param, $name));
            }

            $path .= '/' . $encoded;
        }

        $extra = [...$params, ...$query];

        return ($path === '' ? '/' : $path) . ($extra === [] ? '' : '?' . http_build_query($extra, '', '&', \PHP_QUERY_RFC3986));
    }
}
