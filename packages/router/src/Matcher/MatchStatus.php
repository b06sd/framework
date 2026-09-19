<?php

declare(strict_types=1);

namespace Trunk\Router\Matcher;

enum MatchStatus
{
    case Found;
    case NotFound;
    case MethodNotAllowed;
}
