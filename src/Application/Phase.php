<?php

declare(strict_types=1);

namespace Trunk\Application;

enum Phase
{
    case Created;
    case Registered;
    case Booted;
}
