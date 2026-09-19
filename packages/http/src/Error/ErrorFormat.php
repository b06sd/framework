<?php

declare(strict_types=1);

namespace Trunk\Http\Error;

/**
 * @api
 */
enum ErrorFormat: string
{
    case Json = 'json';
    case Html = 'html';
    case Text = 'text';
}
