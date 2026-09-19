<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Di;

interface PaymentGateway
{
    public function name(): string;
}
