<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\OpenApi;

use Deprecated;
use Psr\Http\Message\ResponseInterface;
use Trunk\Http\Response\ResponseBuilder;
use Trunk\OpenApi\Attribute\Returns;
use Trunk\Router\Attribute\Body;
use Trunk\Router\Attribute\Query;
use Trunk\Tests\Fixtures\Validation\Order;
use Trunk\Tests\Fixtures\Validation\RegisterRequest;
use Trunk\Tests\Fixtures\Validation\SearchRequest;

final readonly class DocumentedOrderController
{
    public function __construct(private ResponseBuilder $responses) {}

    /**
     * List orders.
     */
    #[Returns(200, OrderView::class, list: true)]
    public function index(#[Query] SearchRequest $search): ResponseInterface
    {
        return $this->responses->json([]);
    }

    /**
     * Place an order.
     *
     * Stock is reserved at once; payment follows.
     *
     * @param int $shop the shop
     */
    #[Returns(201, OrderView::class)]
    public function store(int $shop, #[Body] Order $order): ResponseInterface
    {
        return $this->responses->json([], 201);
    }

    #[Returns(200, OrderView::class)]
    public function show(int $id): ResponseInterface
    {
        return $this->responses->json([]);
    }

    #[Deprecated]
    #[Returns(204, description: 'Gone for good')]
    public function remove(int $id): ResponseInterface
    {
        return $this->responses->noContent();
    }

    public function register(#[Body] RegisterRequest $signup): ResponseInterface
    {
        return $this->responses->json([], 201);
    }

    #[Returns(200, Order::class)]
    public function preview(#[Body] Order $order): ResponseInterface
    {
        return $this->responses->json($order);
    }

    public function page(?int $page = null): ResponseInterface
    {
        return $this->responses->json(['page' => $page]);
    }
}
