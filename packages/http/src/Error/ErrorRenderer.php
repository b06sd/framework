<?php

declare(strict_types=1);

namespace Trunk\Http\Error;

use Psr\Http\Message\ServerRequestInterface;
use Trunk\Error\ErrorReport;

/**
 * Turns an ErrorReport into a response body. Register your own with the tag `http.error_renderer`
 * (the Tusk error pages do); return null when you do not handle this format or status and the
 * next renderer, ending with the built-in ones, is used.
 *
 * In production, render only the safe fields (status, code, message, requestId, details). The
 * exception and debug information are for development pages and are empty in production.
 *
 * @api
 */
interface ErrorRenderer
{
    public function render(ErrorReport $report, ErrorFormat $format, ?ServerRequestInterface $request, bool $debug): ?RenderedError;
}
