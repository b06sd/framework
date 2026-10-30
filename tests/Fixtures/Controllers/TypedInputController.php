<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Controllers;

use Psr\Http\Message\ResponseInterface;
use Trunk\Http\Response\ResponseBuilder;
use Trunk\Router\Attribute\Body;
use Trunk\Router\Attribute\Query;
use Trunk\Tests\Fixtures\Validation\JsonOnly;
use Trunk\Tests\Fixtures\Validation\Order;
use Trunk\Tests\Fixtures\Validation\SearchRequest;

final readonly class TypedInputController
{
    public function __construct(private ResponseBuilder $responses) {}

    public function store(#[Body] Order $order, int $shop): ResponseInterface
    {
        return $this->responses->json(['shop' => $shop, 'sku' => $order->sku, 'quantity' => $order->quantity], 201);
    }

    public function search(#[Query] SearchRequest $search): ResponseInterface
    {
        return $this->responses->json(['page' => $search->page, 'q' => $search->q]);
    }

    public function rename(#[Body] JsonOnly $rename): ResponseInterface
    {
        return $this->responses->json(['name' => $rename->name]);
    }

    public function queryFromJsonClass(#[Query] JsonOnly $rename): ResponseInterface
    {
        return $this->responses->json(['name' => $rename->name]);
    }
}
