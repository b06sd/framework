<?php

declare(strict_types=1);

namespace Trunk\Orm\Mapping;

/**
 * @api
 */
enum Type: string
{
    case Int = 'int';
    case String = 'string';
    case Bool = 'bool';
    case Float = 'float';
    case DateTime = 'datetime';
    case Enum = 'enum';
    case Json = 'json';
}
