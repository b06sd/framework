<?php

declare(strict_types=1);

namespace Trunk\Error;

use Throwable;

/**
 * A hook for sending errors elsewhere (an error tracker, a chat channel). Register implementations
 * with the tag `trunk.error_reporter`. A reporter that throws is ignored; it can never turn one
 * error into another.
 *
 * @api
 */
interface ErrorReporter
{
    public function report(Throwable $error, ErrorReport $report): void;
}
