<?php

declare(strict_types=1);

namespace Trunk\Router\Compiler;

use Trunk\Router\Definition\Route;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;
use Trunk\Router\Exception\InvalidRouteException;
use Trunk\Router\Exception\RouteCompilationException;
use Trunk\Router\Pattern\PatternParser;
use Trunk\Router\Pattern\Segment;

/**
 * Turns route definitions into a RouteTable. Static paths become hash lookups; dynamic paths become
 * a few branch-reset regexes per method, each alternative tagged with `(*MARK:routeId)`.
 */
final readonly class RouteCompiler
{
    /** Alternatives per regex; keeps every compiled pattern comfortably below PCRE limits. */
    private const int CHUNK_SIZE = 30;

    public function __construct(private PatternParser $parser = new PatternParser()) {}

    /**
     * @param iterable<RouteProvider> $providers
     *
     * @throws RouteCompilationException
     */
    public function compileProviders(iterable $providers): RouteTable
    {
        $collector = new RouteCollector();

        foreach ($providers as $provider) {
            $provider->routes($collector);
        }

        return $this->compile($collector->routes());
    }

    /**
     * @param list<Route> $routes
     *
     * @throws RouteCompilationException
     */
    public function compile(array $routes): RouteTable
    {
        $errors = [];
        $static = [];
        $signatures = [];
        $alternatives = [];
        $entries = [];
        $names = [];

        foreach ($routes as $id => $route) {
            try {
                $parsed = $this->parser->parse($route->pattern);
            } catch (InvalidRouteException $e) {
                $errors[] = $e->getMessage();

                continue;
            }

            $entries[$id] = [
                'methods' => $route->methods,
                'pattern' => $route->pattern,
                'handler' => $route->handler,
                'name' => $route->name,
                'params' => $parsed->paramNames(),
                'args' => $route->arguments,
                'middleware' => $route->middleware,
            ];

            if ($route->name !== null) {
                if (isset($names[$route->name])) {
                    $errors[] = \sprintf('Route name "%s" is used more than once.', $route->name);
                }

                $names[$route->name] = $id;
            }

            foreach ($route->methods as $method) {
                foreach ($parsed->variants() as $segments) {
                    $signature = $method . ' ' . $this->signature($segments);

                    if (isset($signatures[$signature])) {
                        $errors[] = \sprintf('Duplicate route: %s %s conflicts with %s.', $method, $route->pattern, $signatures[$signature]);

                        continue;
                    }

                    $signatures[$signature] = $route->pattern;

                    if (array_all($segments, static fn(Segment $s): bool => !$s->isParam())) {
                        $static[$method][$this->staticPath($segments)] = $id;
                    } else {
                        $alternatives[$method][] = $this->alternative($segments, $id);
                    }
                }
            }
        }

        $dynamic = [];

        foreach ($alternatives as $method => $list) {
            foreach (array_chunk($list, self::CHUNK_SIZE) as $chunk) {
                $regex = '~^(?|' . implode('|', $chunk) . ')$~D';

                if (@preg_match($regex, '') === false) {
                    $errors[] = \sprintf('The compiled pattern for %s routes is invalid (%s).', $method, preg_last_error_msg());

                    continue;
                }

                $dynamic[(string) $method][] = $regex;
            }
        }

        if ($errors !== []) {
            throw new RouteCompilationException($errors);
        }

        return new RouteTable($static, $dynamic, array_values($entries), $names);
    }

    /**
     * @param list<Segment> $segments
     */
    private function staticPath(array $segments): string
    {
        return '/' . implode('/', array_map(static fn(Segment $s): string => (string) $s->literal, $segments));
    }

    /**
     * @param list<Segment> $segments
     */
    private function signature(array $segments): string
    {
        return '/' . implode('/', array_map(static fn(Segment $s): string => $s->isParam() ? '{' . $s->regex . '}' : (string) $s->literal, $segments));
    }

    /**
     * @param list<Segment> $segments
     */
    private function alternative(array $segments, int $id): string
    {
        $regex = '';

        foreach ($segments as $segment) {
            $regex .= '/' . ($segment->isParam() ? '(' . $segment->regex . ')' : preg_quote((string) $segment->literal, '~'));
        }

        return $regex . '(*MARK:' . $id . ')';
    }
}
