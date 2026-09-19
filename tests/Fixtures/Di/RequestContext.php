<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Di;

use Psr\Http\Message\ServerRequestInterface;

final readonly class RequestContext
{
    public Token $token;

    public function __construct(public ServerRequestInterface $request)
    {
        $this->token = new Token();
    }
}
