<?php

declare(strict_types=1);

namespace Trunk\Database\Exception;

use InvalidArgumentException;

/**
 * The query builder was asked for something unsafe or malformed (a bad identifier, operator,
 * direction or value). Raised before any SQL is built.
 *
 * @api
 */
final class InvalidQueryException extends InvalidArgumentException {}
