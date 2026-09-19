<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Services;

final readonly class Mailer
{
    public function __construct(public Logger $logger, public string $from) {}
}
