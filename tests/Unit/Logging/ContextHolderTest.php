<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Logging;

use Fiber;
use PHPUnit\Framework\TestCase;
use Trunk\Logging\ContextHolder;
use Trunk\Logging\RequestContext;

final class ContextHolderTest extends TestCase
{
    public function test_a_context_is_returned_until_it_is_reset(): void
    {
        // Arrange
        $holder = new ContextHolder();
        $context = new RequestContext('req-1', str_repeat('a', 32), str_repeat('b', 16));

        // Act
        $holder->set($context);

        // Assert
        self::assertSame($context, $holder->get());
        $holder->reset();
        self::assertNull($holder->get());
    }

    public function test_concurrent_fibers_never_see_each_others_context(): void
    {
        // Arrange
        $holder = new ContextHolder();
        $seen = [];
        $worker = static function (string $id) use ($holder, &$seen): void {
            $holder->set(new RequestContext($id, str_repeat('a', 32), str_repeat('b', 16)));
            Fiber::suspend();
            $seen[$id][] = $holder->get()?->requestId;
            Fiber::suspend();
            $seen[$id][] = $holder->get()?->requestId;
        };
        $fibers = [new Fiber($worker), new Fiber($worker), new Fiber($worker)];

        // Act: interleave three "requests" on the same holder
        foreach ($fibers as $i => $fiber) {
            $fiber->start('req-' . $i);
        }
        for ($round = 0; $round < 2; ++$round) {
            foreach (array_reverse($fibers) as $fiber) {
                $fiber->resume();
            }
        }

        // Assert
        ksort($seen);
        self::assertSame(['req-0' => ['req-0', 'req-0'], 'req-1' => ['req-1', 'req-1'], 'req-2' => ['req-2', 'req-2']], $seen);
        self::assertNull($holder->get(), 'nothing leaked into the main context');
    }

    public function test_a_fiber_inherits_the_starting_context_without_changing_it(): void
    {
        // Arrange
        $holder = new ContextHolder();
        $main = new RequestContext('main', str_repeat('a', 32), str_repeat('b', 16));
        $holder->set($main);
        $inside = null;
        $fiber = new Fiber(static function () use ($holder, &$inside): void {
            $inside = $holder->get();
            $holder->set(new RequestContext('child', str_repeat('c', 32), str_repeat('d', 16)));
        });

        // Act
        $fiber->start();

        // Assert
        self::assertSame($main, $inside);
        self::assertSame($main, $holder->get(), 'the child context did not replace the parent one');
    }

    public function test_reset_clears_the_main_and_every_fiber_context(): void
    {
        // Arrange
        $holder = new ContextHolder();
        $after = 'unset';
        $fiber = new Fiber(static function () use ($holder, &$after): void {
            $holder->set(new RequestContext('child', str_repeat('c', 32), str_repeat('d', 16)));
            Fiber::suspend();
            $after = $holder->get()?->requestId;
        });
        $fiber->start();
        $holder->set(new RequestContext('main', str_repeat('a', 32), str_repeat('b', 16)));

        // Act
        $holder->reset();
        $fiber->resume();

        // Assert
        self::assertNull($holder->get());
        self::assertNull($after);
    }
}
