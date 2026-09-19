<?php

declare(strict_types=1);

namespace Trunk\Router\Pattern;

final readonly class ParsedPattern
{
    /**
     * @param list<Segment> $segments
     */
    public function __construct(public array $segments) {}

    public function isStatic(): bool
    {
        return array_all($this->segments, static fn(Segment $s): bool => !$s->isParam());
    }

    /**
     * @return list<string>
     */
    public function paramNames(): array
    {
        $names = [];

        foreach ($this->segments as $segment) {
            if ($segment->param !== null) {
                $names[] = $segment->param;
            }
        }

        return $names;
    }

    /**
     * Concrete segment lists this pattern matches: the full one, plus one without the optional tail.
     *
     * @return list<list<Segment>>
     */
    public function variants(): array
    {
        $last = $this->segments[\count($this->segments) - 1] ?? null;

        if ($last === null || !$last->optional) {
            return [$this->segments];
        }

        return [$this->segments, \array_slice($this->segments, 0, -1)];
    }
}
