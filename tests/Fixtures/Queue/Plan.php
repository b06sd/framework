<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Queue;

enum Plan: string
{
    case Free = 'free';
    case Pro = 'pro';
}
