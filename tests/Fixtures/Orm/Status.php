<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

enum Status: string
{
    case Active = 'active';
    case Blocked = 'blocked';
}
