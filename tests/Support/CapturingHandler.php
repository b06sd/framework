<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Trunk\Http\Message\Response;

final class CapturingHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $seen = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->seen = $request;

        return new Response(200);
    }
}
