<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\OpenApi;

enum OrderStatus: string
{
    case Open = 'open';
    case Shipped = 'shipped';
}
