<?php

declare(strict_types=1);

namespace Trunk\Foundation\Exception;

use RuntimeException;

/**
 * A problem with a capability or its metadata. The message says what to do about it.
 */
final class CapabilityException extends RuntimeException {}
