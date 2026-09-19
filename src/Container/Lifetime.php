<?php

declare(strict_types=1);

namespace Trunk\Container;

/**
 * @api
 */
enum Lifetime
{
    /** One instance for the lifetime of the application (worker). */
    case Singleton;

    /** One instance per scope (per HTTP request). */
    case Scoped;

    /** A new instance every time it is resolved. */
    case Transient;
}
