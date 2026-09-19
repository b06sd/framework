<?php

declare(strict_types=1);

namespace Trunk\Cache\Exception;

use InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

/**
 * @api
 */
final class InvalidKeyException extends InvalidArgumentException implements PsrInvalidArgumentException {}
