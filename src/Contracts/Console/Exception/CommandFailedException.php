<?php

declare(strict_types=1);

namespace Trunk\Contracts\Console\Exception;

use RuntimeException;

/**
 * A command cannot continue. The message says what happened and what to do about it. Exit code 1.
 *
 * @api
 */
final class CommandFailedException extends RuntimeException {}
