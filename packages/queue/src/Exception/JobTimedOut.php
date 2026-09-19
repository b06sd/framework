<?php

declare(strict_types=1);

namespace Trunk\Queue\Exception;

use RuntimeException;

final class JobTimedOut extends RuntimeException
{
    public static function after(int $seconds): self
    {
        return new self(\sprintf('The job exceeded its %d second timeout.', $seconds));
    }
}
