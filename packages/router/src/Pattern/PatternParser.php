<?php

declare(strict_types=1);

namespace Trunk\Router\Pattern;

use Trunk\Router\Exception\InvalidRouteException;

/**
 * Parses `/users/{id:int}/posts/{slug?}`. Every segment is either fully literal or exactly one
 * parameter, so adjacent parameters and ambiguous mixed segments cannot exist.
 */
final readonly class PatternParser
{
    private const string LITERAL = "/^[A-Za-z0-9._~!\$&'()*+,;=:@%-]+$/D";

    private const string PARAM = '/^\{([A-Za-z_][A-Za-z0-9_]*)(\?)?(?::(.+))?\}$/D';

    public function __construct(private Constraints $constraints = new Constraints()) {}

    public function parse(string $pattern): ParsedPattern
    {
        if ($pattern === '' || $pattern[0] !== '/') {
            throw new InvalidRouteException(\sprintf('Route pattern "%s" must start with "/".', $pattern));
        }

        if ($pattern === '/') {
            return new ParsedPattern([]);
        }

        $parts = explode('/', substr($pattern, 1));
        $segments = [];
        $names = [];
        $last = \count($parts) - 1;

        foreach ($parts as $index => $part) {
            if ($part === '') {
                throw new InvalidRouteException(\sprintf('Route pattern "%s" contains an empty segment.', $pattern));
            }

            if (preg_match(self::LITERAL, $part) === 1) {
                $segments[] = Segment::literal($part);

                continue;
            }

            if (preg_match(self::PARAM, $part, $m) !== 1) {
                throw new InvalidRouteException(\sprintf('Invalid segment "%s" in route pattern "%s".', $part, $pattern));
            }

            $name = $m[1];
            $optional = ($m[2] ?? '') === '?';
            $regex = isset($m[3]) ? $this->constraints->resolve($m[3]) : Constraints::DEFAULT;

            if (\in_array($name, $names, true)) {
                throw new InvalidRouteException(\sprintf('Parameter "%s" is used twice in "%s".', $name, $pattern));
            }

            if (($optional || $this->constraints->isCatchAll($regex)) && $index !== $last) {
                throw new InvalidRouteException(\sprintf('Optional and catch-all parameters must be the last segment in "%s".', $pattern));
            }

            $names[] = $name;
            $segments[] = Segment::param($name, $regex, $optional);
        }

        return new ParsedPattern($segments);
    }
}
