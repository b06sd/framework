<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Services;

final readonly class Greeter
{
    public function __construct(public Mailer $mailer, public int $retries = 3) {}
}
