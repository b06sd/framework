<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Auth;

use Psr\Http\Message\ResponseInterface;
use Trunk\Auth\Auth;
use Trunk\Http\Response\ResponseBuilder;

final readonly class JwtController
{
    public function __construct(private Auth $auth, private ResponseBuilder $responses) {}

    public function whoami(): ResponseInterface
    {
        return $this->responses->json([
            'id' => $this->auth->user()?->authId(),
            'subject' => $this->auth->claims()?->subject,
            'scopes' => $this->auth->claims()?->scopes,
            'canRead' => $this->auth->tokenCan('read'),
            'canWrite' => $this->auth->tokenCan('write'),
        ]);
    }
}
