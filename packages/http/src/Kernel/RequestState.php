<?php

declare(strict_types=1);

namespace Trunk\Http\Kernel;

/**
 * Facts the pipeline learns while handling one request that the kernel wants afterwards (the matched
 * route, for metric labels). It travels as a request attribute; PSR-7 requests are immutable, so
 * this small mutable holder is how an inner step reports back to the kernel.
 */
final class RequestState
{
    /** A low-cardinality name of the matched route (never the raw path). */
    public ?string $route = null;
}
