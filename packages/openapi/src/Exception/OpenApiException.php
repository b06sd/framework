<?php

declare(strict_types=1);

namespace Trunk\OpenApi\Exception;

use RuntimeException;

/**
 * The document cannot be generated (a `#[Returns]` class that does not exist, a broken route) or
 * read (no build/openapi.json in production). The message says what to fix.
 *
 * @api
 */
final class OpenApiException extends RuntimeException {}
