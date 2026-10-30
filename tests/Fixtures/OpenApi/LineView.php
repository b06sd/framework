<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\OpenApi;

use BcMath\Number;

final readonly class LineView
{
    public function __construct(public string $sku, public Number $price) {}
}
