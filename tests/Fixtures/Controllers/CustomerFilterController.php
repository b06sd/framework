<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Trunk\Http\Response\ResponseBuilder;
use Trunk\Tests\Fixtures\Orm\Customer;
use Trunk\Tests\Support\OrmHarness;

/**
 * Runs real ORM queries built from the request's query string, the way an application's list endpoint does.
 */
final readonly class CustomerFilterController
{
    public function __construct(private ResponseBuilder $responses) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = new OrmHarness()->manager()->repository(Customer::class)->query()->readOnly();
        $params = $request->getQueryParams();
        $query = $query->filter(array_diff_key($params, ['sort' => true]));
        $sort = $params['sort'] ?? null;

        if (\is_string($sort)) {
            $query = $query->sortBy($sort);
        }

        return $this->responses->json(['count' => \count($query->limit(5)->get())]);
    }

    public function withTypo(): ResponseInterface
    {
        $query = new OrmHarness()->manager()->repository(Customer::class)->query()->readOnly()->with('typo');

        return $this->responses->json(['count' => \count($query->limit(5)->get())]);
    }
}
