<?php

declare(strict_types=1);

namespace Trunk\Cache\Exception;

use Psr\SimpleCache\CacheException as PsrCacheException;
use RuntimeException;

/**
 * @api
 */
final class CacheException extends RuntimeException implements PsrCacheException {}
