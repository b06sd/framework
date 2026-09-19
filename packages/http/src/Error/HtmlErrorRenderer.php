<?php

declare(strict_types=1);

namespace Trunk\Http\Error;

use Psr\Http\Message\ServerRequestInterface;
use Trunk\Error\ErrorReport;

/**
 * The built-in HTML page. Production: status, safe message and request id only. Development: the
 * full exception page (class, message, fix, location with source lines, trace without arguments,
 * request summary with secrets removed). Everything is escaped; the page ships its own strict CSP.
 */
final readonly class HtmlErrorRenderer implements ErrorRenderer
{
    private const string CSP = "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

    private const array HIDDEN_HEADERS = ['authorization', 'cookie', 'set-cookie', 'proxy-authorization', 'x-api-key', 'x-auth-token'];

    public function render(ErrorReport $report, ErrorFormat $format, ?ServerRequestInterface $request, bool $debug): ?RenderedError
    {
        if ($format !== ErrorFormat::Html) {
            return null;
        }

        $title = $report->status . ' ' . self::e($report->public ? $report->message : 'Internal Server Error');
        $body = '<h1>' . $title . '</h1><p>' . self::e($report->public ? $report->code : 'An unexpected error occurred.') . '</p>';

        if ($report->requestId !== null) {
            $body .= '<p class="meta">Request ID: <code>' . self::e($report->requestId) . '</code></p>';
        }

        if ($debug && $report->debug !== null) {
            $body .= $this->debug($report, $request);
        }

        $page = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $title . '</title><style>body{font:16px/1.5 system-ui,sans-serif;margin:2rem auto;max-width:60rem;padding:0 1rem;color:#1a1a1a}h1{font-size:1.6rem}.meta,.trace{color:#555}pre{background:#f5f5f5;padding:.75rem;overflow:auto;border-radius:4px;font-size:.85rem}.cur{background:#ffe3e3;display:block}.hint{background:#e6f4ea;padding:.75rem;border-radius:4px}code{background:#f0f0f0;padding:0 .25rem}</style></head><body>' . $body . '</body></html>';

        return new RenderedError('text/html; charset=utf-8', $page, ['Content-Security-Policy' => self::CSP]);
    }

    private function debug(ErrorReport $report, ?ServerRequestInterface $request): string
    {
        $info = $report->debug;

        if ($info === null) {
            return '';
        }

        $html = '<hr><p><strong>' . self::e($info->class) . '</strong></p><p>' . self::e($info->message) . '</p>';

        if ($report->internalCode !== null) {
            $html .= '<p class="meta">Code: <code>' . self::e($report->internalCode) . '</code></p>';
        }

        if ($info->hint !== null) {
            $html .= '<p class="hint"><strong>How to fix:</strong> ' . self::e($info->hint) . '</p>';
        }

        if ($report->public) {
            return $html;
        }

        $html .= '<p class="meta">' . self::e($info->file) . ':' . $info->line . '</p>';

        if ($info->snippet !== []) {
            $html .= '<pre>';

            foreach ($info->snippet as $line) {
                $text = str_pad((string) $line['line'], 5) . self::e($line['code']);
                $html .= $line['current'] ? '<span class="cur">' . $text . '</span>' : $text . "\n";
            }

            $html .= '</pre>';
        }

        $html .= '<pre class="trace">' . self::e(implode("\n", $info->trace)) . '</pre>';

        if ($request !== null) {
            $html .= '<h3>Request</h3><pre>' . self::e($request->getMethod() . ' ' . $request->getUri()->getPath()) . "\n" . self::e($this->headers($request)) . '</pre>';
        }

        return $html;
    }

    private function headers(ServerRequestInterface $request): string
    {
        $lines = [];

        foreach ($request->getHeaders() as $name => $values) {
            $lines[] = $name . ': ' . (\in_array(strtolower($name), self::HIDDEN_HEADERS, true) ? '[REDACTED]' : implode(', ', $values));
        }

        return implode("\n", $lines);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
