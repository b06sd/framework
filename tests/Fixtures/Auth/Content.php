<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Auth;

abstract class Content
{
    public function __construct(public readonly string $id, public readonly string $ownerId) {}
}
