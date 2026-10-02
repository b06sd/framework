<?php

declare(strict_types=1);

namespace Trunk\Orm\UnitOfWork;

/**
 * @api
 */
enum ChangeKind: string
{
    case Insert = 'insert';
    case Update = 'update';
    case Delete = 'delete';
}
