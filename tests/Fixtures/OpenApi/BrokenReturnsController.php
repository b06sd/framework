<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\OpenApi;

use Psr\Http\Message\ResponseInterface;
use Trunk\Http\Factory\HttpFactory;
use Trunk\OpenApi\Attribute\Returns;

final readonly class BrokenReturnsController
{
    /** @phpstan-ignore argument.type (the class is missing on purpose) */
    #[Returns(200, 'Trunk\Tests\Fixtures\OpenApi\Missing')]
    public function show(): ResponseInterface
    {
        return new HttpFactory()->createResponse();
    }
}
