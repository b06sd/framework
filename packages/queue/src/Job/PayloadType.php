<?php

declare(strict_types=1);

namespace Trunk\Queue\Job;

enum PayloadType: string
{
    case Int = 'int';
    case Float = 'float';
    case String = 'string';
    case Bool = 'bool';
    case Array = 'array';
    case Enum = 'enum';
    case DateTime = 'datetime';
}
