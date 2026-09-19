<?php

declare(strict_types=1);

namespace Trunk\Queue\Exception;

use RuntimeException;

/**
 * The stored job name is not in the compiled job list. The name is only ever a lookup key; it is
 * never used to load a class.
 *
 * @api
 */
final class UnknownJob extends RuntimeException implements PermanentFailure
{
    public static function named(string $name): self
    {
        $safe = preg_replace('/[^A-Za-z0-9_.\\\\\-]/', '?', substr($name, 0, 60)) ?? '?';

        return new self(\sprintf('"%s" is not a registered job. Put the class in app/Jobs (or list it in queue.jobs) and run `trunk build`.', $safe));
    }
}
