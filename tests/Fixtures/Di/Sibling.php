<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Di;

final readonly class Sibling
{
    public function __construct(public RequestContext $context) {}
}
