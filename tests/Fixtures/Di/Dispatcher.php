<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Di;

final readonly class Dispatcher
{
    /**
     * @param list<Listener> $listeners
     */
    public function __construct(public array $listeners) {}
}
