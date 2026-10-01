<?php

declare(strict_types=1);

namespace Trunk\Mail\Exception;

use RuntimeException;

/**
 * An email could not be sent, or mail is not set up correctly. The message names the fix; the
 * provider's own reason (which may echo addresses) goes to the log, not here.
 *
 * @api
 */
final class MailException extends RuntimeException {}
