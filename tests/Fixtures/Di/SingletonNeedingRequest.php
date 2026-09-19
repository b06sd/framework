<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Di;

use Psr\Http\Message\ServerRequestInterface;

final readonly class SingletonNeedingRequest
{
    public function __construct(public ServerRequestInterface $request) {}
}
