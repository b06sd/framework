<?php

declare(strict_types=1);

namespace Trunk\Schedule;

/**
 * @internal
 */
enum FrequencyKind
{
    case Minutes;
    case Hourly;
    case Daily;
    case Weekly;
}
