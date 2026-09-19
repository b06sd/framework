<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Di;

final readonly class ConfiguredThing
{
    public function __construct(public string $name, public int $workers) {}
}
