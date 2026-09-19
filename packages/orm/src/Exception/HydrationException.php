<?php

declare(strict_types=1);

namespace Trunk\Orm\Exception;

use RuntimeException;

/**
 * A database value did not fit the mapped type. The message names the entity, column and the
 * types involved, never the value itself (it may be sensitive).
 *
 * @api
 */
final class HydrationException extends RuntimeException {}
