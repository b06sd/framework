<?php

declare(strict_types=1);

namespace Trunk\Orm\Diagnostics;

final readonly class NPlusOneFinding
{
    public function __construct(
        public string $sql,
        public int $count,
    ) {}

    public function message(): string
    {
        return \sprintf('The same query ran %d times: %s. If this loads related rows one parent at a time, load them together with ->with(\'relation\') or whereIn().', $this->count, $this->sql);
    }
}
