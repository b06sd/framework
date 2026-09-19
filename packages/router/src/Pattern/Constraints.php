<?php

declare(strict_types=1);

namespace Trunk\Router\Pattern;

use Trunk\Router\Exception\InvalidRouteException;

/**
 * Resolves a constraint spec to a regex fragment. Custom specs are limited to a single character
 * class (or \d \w) with a bounded, non-empty quantifier, so every compiled pattern is linear-time:
 * no groups, alternation, backreferences, lookarounds or nested quantifiers are possible.
 */
final class Constraints
{
    public const string DEFAULT = '[^/]+';

    private const array ALIASES = [
        'int' => '[0-9]+',
        'alpha' => '[A-Za-z]+',
        'alnum' => '[A-Za-z0-9]+',
        'slug' => '[a-z0-9-]+',
        'uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
        'any' => '.+',
    ];

    private const string CUSTOM = '/^(\\\\[dwDW]|\[\^?(?:\\\\[^\x00]|[^\]\\\\~\x00\s])+\])(\+|\{[1-9]\d{0,2}(?:,\d{1,3})?\})?$/D';

    public function resolve(string $spec): string
    {
        if (isset(self::ALIASES[$spec])) {
            return self::ALIASES[$spec];
        }

        if (preg_match(self::CUSTOM, $spec, $m) !== 1) {
            throw new InvalidRouteException(\sprintf(
                'Unsupported constraint "%s": use an alias (%s) or a single character class such as [a-z]+ or \d{2,4}.',
                $spec,
                implode(', ', array_keys(self::ALIASES)),
            ));
        }

        // A segment constraint must never be able to consume "/", or a parameter could span segments.
        if (preg_match('~^' . $m[1] . '$~D', '/') === 1) {
            throw new InvalidRouteException(\sprintf('Constraint "%s" can match "/"; use a narrower character class.', $spec));
        }

        // A bare class or \d without a quantifier matches exactly one character.
        return $spec;
    }

    public function isCatchAll(string $regex): bool
    {
        return $regex === self::ALIASES['any'];
    }
}
