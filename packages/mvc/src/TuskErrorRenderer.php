<?php

declare(strict_types=1);

namespace Trunk\Mvc;

use Psr\Http\Message\ServerRequestInterface;
use Trunk\Error\ErrorReport;
use Trunk\Http\Error\ErrorFormat;
use Trunk\Http\Error\ErrorRenderer;
use Trunk\Http\Error\RenderedError;
use Trunk\Tusk\Exception\TemplateNotFoundException;
use Trunk\Tusk\Renderer;

/**
 * Renders HTML errors with your own Tusk views: `resources/views/errors/{status}.tusk.php` (for
 * example 404), then `errors/error.tusk.php`. The view receives only `status`, `code`, `message`
 * and `requestId`, never the exception, so a template cannot leak internals. When neither view
 * exists (or in development, where the detailed page is more useful) the built-in page is used.
 */
final readonly class TuskErrorRenderer implements ErrorRenderer
{
    public function __construct(private Renderer $views) {}

    public function render(ErrorReport $report, ErrorFormat $format, ?ServerRequestInterface $request, bool $debug): ?RenderedError
    {
        if ($format !== ErrorFormat::Html || $debug) {
            return null;
        }

        $data = ['status' => $report->status, 'code' => $report->code, 'message' => $report->message, 'requestId' => $report->requestId];

        foreach (['errors/' . $report->status, 'errors/error'] as $view) {
            try {
                return new RenderedError('text/html; charset=utf-8', $this->views->render($view, $data));
            } catch (TemplateNotFoundException) {
                continue;
            }
        }

        return null;
    }
}
