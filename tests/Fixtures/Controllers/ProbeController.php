<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Controllers;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Trunk\Tests\Fixtures\Di\RequestContext;
use Trunk\Tests\Fixtures\Di\Sibling;
use Trunk\Tests\Fixtures\Di\Token;

final readonly class ProbeController
{
    public function __construct(
        private ResponseFactoryInterface $responses,
        private RequestContext $context,
        private Sibling $sibling,
        private Token $shared,
    ) {}

    public function show(): ResponseInterface
    {
        $response = $this->responses->createResponse(200);
        $response->getBody()->write(\sprintf(
            'scoped=%s;same=%s;singleton=%s;path=%s',
            $this->context->token->value,
            $this->context === $this->sibling->context ? 'yes' : 'no',
            $this->shared->value,
            $this->context->request->getUri()->getPath(),
        ));

        return $response;
    }
}
