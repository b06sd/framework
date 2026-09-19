<?php

declare(strict_types=1);

namespace Trunk\Queue\Exception;

use LogicException;

/**
 * Misuse of the queue API (an unregistered job class, a bad queue name, an oversized payload).
 *
 * @api
 */
final class QueueException extends LogicException {}
