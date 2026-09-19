<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Di;

final class StripeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'stripe';
    }
}
