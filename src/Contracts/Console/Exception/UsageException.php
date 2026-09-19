<?php

declare(strict_types=1);

namespace Trunk\Contracts\Console\Exception;

use RuntimeException;

/**
 * The command line was used incorrectly. Exit code 2.
 *
 * @api
 */
final class UsageException extends RuntimeException {}
