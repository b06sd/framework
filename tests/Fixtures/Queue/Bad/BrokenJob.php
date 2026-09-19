<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Queue\Bad;

use Closure;
use Psr\Container\ContainerInterface;
use stdClass;
use Trunk\Queue\Job\Job;

/**
 * Deliberately wrong in many ways at once.
 */
final class BrokenJob implements Job
{
    private int $hidden = 0;

    public function __construct(
        public stdClass $entity,
        public int|string $either,
        public int $hiddenParam = 0,
        int $plain = 0,
        public ?Closure $callback = null,
    ) {
        $this->hidden = $plain;
    }

    public function handle(ContainerInterface $container, string $name, ?BrokenJob $maybe): void {}

    public function hidden(): int
    {
        return $this->hidden;
    }
}
