<?php

declare(strict_types=1);

namespace Trunk\Http\Error;

use Psr\Http\Message\ServerRequestInterface;
use Trunk\Error\DebugInfo;
use Trunk\Error\ErrorReport;

/**
 * `{"error":{"code":"...","message":"...","requestId":"..."}}`, plus `details` for public errors that
 * have them, and a `debug` block only in development.
 */
final readonly class JsonErrorRenderer implements ErrorRenderer
{
    public function render(ErrorReport $report, ErrorFormat $format, ?ServerRequestInterface $request, bool $debug): ?RenderedError
    {
        if ($format !== ErrorFormat::Json) {
            return null;
        }

        $error = ['code' => $report->code, 'message' => $report->message, 'requestId' => $report->requestId];

        if ($report->public && $report->details !== []) {
            $error['details'] = $report->details;
        }

        if ($debug && $report->debug !== null) {
            $error['debug'] = self::debug($report->debug, $report->internalCode);
        }

        return new RenderedError('application/json; charset=utf-8', (string) json_encode(['error' => $error], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function debug(DebugInfo $info, ?string $internalCode): array
    {
        return ['exception' => $info->class, 'message' => $info->message, 'internalCode' => $internalCode, 'hint' => $info->hint, 'file' => $info->file, 'line' => $info->line, 'trace' => $info->trace, 'previous' => $info->previous];
    }
}
