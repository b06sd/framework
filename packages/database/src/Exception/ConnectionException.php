<?php

declare(strict_types=1);

namespace Trunk\Database\Exception;

use RuntimeException;

/**
 * A connection could not be configured or opened. Never contains the DSN, host credentials or password.
 *
 * @api
 */
final class ConnectionException extends RuntimeException {}
