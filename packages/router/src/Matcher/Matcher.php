<?php

declare(strict_types=1);

namespace Trunk\Router\Matcher;

use Psr\Http\Message\ServerRequestInterface;
use Trunk\Router\Compiler\RouteTable;

/**
 * Matches a method and raw (percent-encoded) path against a RouteTable. No reflection, no filesystem,
 * no allocation beyond the result. Any regex engine error fails closed as "not found".
 */
final readonly class Matcher
{
    public function __construct(private RouteTable $table) {}

    public function matchRequest(ServerRequestInterface $request): MatchResult
    {
        $path = $request->getUri()->getPath();

        return $this->match($request->getMethod(), $path === '' ? '/' : $path);
    }

    public function match(string $method, string $path): MatchResult
    {
        $hit = $this->lookup($method, $path) ?? ($method === 'HEAD' ? $this->lookup('GET', $path) : null);

        if ($hit !== null) {
            $route = $this->table->routes[$hit[0]];

            return MatchResult::found($hit[0], $route['handler'], $route['name'], $hit[1], $route['args'], $route['middleware']);
        }

        $allowed = [];

        foreach ($this->table->methods() as $other) {
            if ($other !== $method && $this->lookup($other, $path) !== null) {
                $allowed[] = $other;
            }
        }

        if (\in_array('GET', $allowed, true) && !\in_array('HEAD', $allowed, true)) {
            $allowed[] = 'HEAD';
        }

        sort($allowed);

        return $allowed === [] ? MatchResult::notFound() : MatchResult::methodNotAllowed($allowed);
    }

    /**
     * @return array{int, array<string, string>}|null route id and decoded parameters
     */
    private function lookup(string $method, string $path): ?array
    {
        if ($path === '' || $path[0] !== '/') {
            return null;
        }

        if (isset($this->table->static[$method][$path])) {
            return [$this->table->static[$method][$path], []];
        }

        foreach ($this->table->dynamic[$method] ?? [] as $regex) {
            $matched = preg_match($regex, $path, $captures);

            if ($matched === false) {
                return null;
            }

            if ($matched === 1) {
                $id = (int) ($captures['MARK'] ?? -1);
                $params = $this->bind($this->table->routes[$id]['params'] ?? [], $captures);

                return $params === null ? null : [$id, $params];
            }
        }

        return null;
    }

    /**
     * @param list<string>            $names
     * @param array<array-key, mixed> $captures
     *
     * @return array<string, string>|null null when a decoded value is unsafe
     */
    private function bind(array $names, array $captures): ?array
    {
        $params = [];

        foreach ($names as $index => $name) {
            $raw = $captures[$index + 1] ?? '';

            if (!\is_string($raw) || $raw === '') {
                continue;
            }

            $value = rawurldecode($raw);

            if (str_contains($value, "\0") || preg_match('//u', $value) !== 1) {
                return null;
            }

            $params[$name] = $value;
        }

        return $params;
    }
}
