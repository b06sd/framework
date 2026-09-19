<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Di;

final class BListener implements Listener
{
    public function id(): string
    {
        return 'b';
    }
}
