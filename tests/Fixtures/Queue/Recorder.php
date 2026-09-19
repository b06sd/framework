<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Queue;

/**
 * A scoped-style test service that jobs write into.
 */
final class Recorder
{
    /** @var list<string> */
    public array $events = [];
}
