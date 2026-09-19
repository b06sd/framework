<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Auth;

use Psr\Http\Message\ResponseInterface;
use Trunk\Auth\Authorization\Gate;
use Trunk\Http\Response\ResponseBuilder;

final readonly class PostController
{
    public function __construct(private ResponseBuilder $responses, private Gate $gate) {}

    public function update(string $owner): ResponseInterface
    {
        $this->gate->authorize('update', new Post('1', $owner));

        return $this->responses->json(['updated' => true]);
    }

    public function admin(): ResponseInterface
    {
        $this->gate->authorize('manage-users');

        return $this->responses->json(['admin' => true]);
    }
}
