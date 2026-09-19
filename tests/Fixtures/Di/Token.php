<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Di;

final readonly class Token
{
    public string $value;

    public function __construct()
    {
        $this->value = bin2hex(random_bytes(6));
    }
}
