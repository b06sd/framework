<?php

declare(strict_types=1);

namespace Trunk\Router\Definition;

enum ArgumentKind: string
{
    /** The current ServerRequestInterface. */
    case Request = 'request';

    /** A route parameter, coerced to a scalar type. */
    case Param = 'param';

    /** A constant default value. */
    case Default = 'default';
}
