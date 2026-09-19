<?php

declare(strict_types=1);

namespace Trunk\Http\Exception;

use InvalidArgumentException;

/**
 * @api
 */
final class UnsafeRedirectException extends InvalidArgumentException {}
