<?php

declare(strict_types=1);

namespace Trunk\Http\Error;

use Psr\Http\Message\ServerRequestInterface;
use Trunk\Error\ErrorReport;

/**
 * Plain text: the message (the standard reason phrase for internal errors). In development it adds
 * the exception class, hint and location for internal errors, and the code for deliberate ones.
 */
final readonly class TextErrorRenderer implements ErrorRenderer
{
    public function render(ErrorReport $report, ErrorFormat $format, ?ServerRequestInterface $request, bool $debug): RenderedError
    {
        $message = $report->public ? $report->message : 'Internal Server Error';

        if (!$debug || $report->debug === null) {
            return new RenderedError('text/plain; charset=utf-8', $message);
        }

        if ($report->public) {
            return new RenderedError('text/plain; charset=utf-8', $report->status . ': ' . $report->exception->getMessage());
        }

        $debugInfo = $report->debug;
        $text = $debugInfo->class . ': ' . $debugInfo->message . "\n";

        if ($debugInfo->hint !== null) {
            $text .= "\nFix: " . $debugInfo->hint . "\n";
        }

        $text .= "\nLocation: " . $debugInfo->file . ':' . $debugInfo->line . "\n\n" . implode("\n", $debugInfo->trace);

        return new RenderedError('text/plain; charset=utf-8', $text);
    }
}
