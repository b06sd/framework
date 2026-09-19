<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Services;

final readonly class NeedsScalar
{
    public function __construct(public string $name) {}
}
