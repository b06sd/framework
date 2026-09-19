<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Di;

final readonly class PaymentService
{
    public function __construct(public PaymentGateway $gateway, public string $currency = 'USD') {}
}
