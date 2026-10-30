<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Controllers;

use DateTimeInterface;
use Psr\Http\Message\ServerRequestInterface;
use Trunk\Foundation\Environment;
use Trunk\Router\Attribute\Body;
use Trunk\Router\Attribute\Query;
use Trunk\Tests\Fixtures\Validation\Order;

final class PlanController
{
    public function ok(ServerRequestInterface $request, int $id, string $q = 'x'): void {}

    public function nullableOptional(?string $page = null): void {}

    public function requiredForOptional(string $page): void {}

    public function variadic(string ...$parts): void {}

    public function union(int|string $id): void {}

    public function classParam(DateTimeInterface $id): void {}

    public function unbindable(mixed $other): void {}

    public function enumDefault(Environment $mode = Environment::Local): void {}

    public function body(#[Body] Order $order, int $id): void {}

    public function nullableBody(#[Body] ?Order $order): void {}

    public function scalarBody(#[Body] string $order): void {}

    public function bodyAndQuery(#[Body, Query] Order $order): void {}

    public function twoBodies(#[Body] Order $first, #[Body] Order $second): void {}

    public function bodyOnRouteParam(#[Body] int $id): void {}
}
