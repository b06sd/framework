<?php

declare(strict_types=1);

namespace Trunk\Queue\Exception;

/**
 * A failure that retrying cannot fix (an unknown job, a payload that does not decode, a job that
 * asked not to be retried). The worker sends such jobs straight to the failed table.
 *
 * @api
 */
interface PermanentFailure {}
