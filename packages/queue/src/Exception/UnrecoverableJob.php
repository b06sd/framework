<?php

declare(strict_types=1);

namespace Trunk\Queue\Exception;

use RuntimeException;

/**
 * Throw this from a job's handle() to fail it permanently without further retries.
 *
 * @api
 */
final class UnrecoverableJob extends RuntimeException implements PermanentFailure {}
