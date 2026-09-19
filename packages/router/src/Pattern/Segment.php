<?php

declare(strict_types=1);

namespace Trunk\Router\Pattern;

/**
 * One path segment: either a literal or a single parameter with a constraint regex.
 */
final readonly class Segment
{
    private function __construct(
        public ?string $literal,
        public ?string $param,
        public ?string $regex,
        public bool $optional,
    ) {}

    public static function literal(string $literal): self
    {
        return new self($literal, null, null, false);
    }

    public static function param(string $name, string $regex, bool $optional): self
    {
        return new self(null, $name, $regex, $optional);
    }

    public function isParam(): bool
    {
        return $this->param !== null;
    }
}
