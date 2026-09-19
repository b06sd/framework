<?php

declare(strict_types=1);

namespace Trunk\Lifecycle;

enum MemoryVerdict: string
{
    /** Nothing to report. */
    case Ok = 'ok';
    /** Memory keeps climbing well above the baseline: worth investigating, still running. */
    case Growing = 'growing';
    /** The limit was reached: finish cleanly and let the supervisor start a fresh process. */
    case Restart = 'restart';
}
