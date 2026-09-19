<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Cache;

use Trunk\Cache\Store\ArrayStore;
use Trunk\Cache\Store\Store;
use Trunk\Contracts\Clock;

final class ArrayStoreTest extends CacheBehaviourTestCase
{
    protected function store(Clock $clock): Store
    {
        return new ArrayStore($clock);
    }
}
