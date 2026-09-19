<?php

declare(strict_types=1);

namespace Trunk\Auth\Exception;

use InvalidArgumentException;

/**
 * A password was refused by the password rules (too short, too long). The message states the rule and
 * never contains the password.
 *
 * @api
 */
final class InvalidPasswordException extends InvalidArgumentException {}
