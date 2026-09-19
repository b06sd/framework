<?php

declare(strict_types=1);

namespace Trunk\Orm\Exception;

use InvalidArgumentException;

/**
 * Untrusted query input (a filter or sort from a request) was not acceptable. Never echoes the value.
 *
 * @api
 */
final class InvalidFilter extends InvalidArgumentException {}
