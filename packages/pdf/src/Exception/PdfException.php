<?php

declare(strict_types=1);

namespace Trunk\Pdf\Exception;

use RuntimeException;

/**
 * A PDF could not be made, or the settings are wrong. The message names the fix.
 *
 * @api
 */
final class PdfException extends RuntimeException {}
