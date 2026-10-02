<?php

declare(strict_types=1);

namespace Trunk\Storage\Exception;

use RuntimeException;

/**
 * Storage is not set up correctly, or an upload was refused. The message names the fix.
 *
 * @api
 */
final class StorageException extends RuntimeException {}
