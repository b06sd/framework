<?php

declare(strict_types=1);

namespace Trunk\Logging;

use Fiber;
use Trunk\Lifecycle\LifecycleAware;
use WeakMap;

/**
 * Holds the current RequestContext so the logger can enrich records without global state. It is an
 * explicit service, set by the kernel or worker at the start of a unit of work and cleared in a
 * `finally` block.
 *
 * Fiber-safe: a context set inside a fiber belongs to that fiber only (concurrent fibers never see
 * each other's request), and a fiber that sets nothing reads the context of the code that started it.
 * Fibers are the only in-process concurrency PHP itself offers; a runtime that runs requests in
 * separate threads or coroutines outside `Fiber` must give each its own container scope. `reset()`
 * clears the main context and every fiber's, so nothing outlives the unit of work.
 *
 * @api
 */
final class ContextHolder implements LifecycleAware
{
    private ?RequestContext $main = null;

    /** @var WeakMap<object, RequestContext> */
    private WeakMap $fibers;

    public function __construct()
    {
        $this->fibers = new WeakMap();
    }

    public function set(RequestContext $context): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            $this->main = $context;

            return;
        }

        $this->fibers[$fiber] = $context;
    }

    public function get(): ?RequestContext
    {
        $fiber = Fiber::getCurrent();

        return ($fiber !== null ? ($this->fibers[$fiber] ?? null) : null) ?? $this->main;
    }

    public function reset(): void
    {
        $this->main = null;
        $this->fibers = new WeakMap();
    }
}
