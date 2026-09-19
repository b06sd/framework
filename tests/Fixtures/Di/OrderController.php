<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Di;

final readonly class OrderController
{
    public function __construct(public PaymentService $payments) {}
}
