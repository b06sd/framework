<?php

declare(strict_types=1);

namespace Trunk\Http\Error;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Chooses how an error is presented. A browser (Accept lists text/html first) gets a page; an API
 * client (Accept: application/json, a JSON body, or XMLHttpRequest) gets JSON; anything else, such
 * as curl's `*` `/` `*`, gets plain text. `errors.format` can force json, html or text.
 */
final class ErrorNegotiator
{
    public function negotiate(?ServerRequestInterface $request, string $configured = 'auto'): ErrorFormat
    {
        $forced = ErrorFormat::tryFrom($configured);

        if ($forced !== null) {
            return $forced;
        }

        if ($request === null) {
            return ErrorFormat::Text;
        }

        foreach ($this->accepted($request->getHeaderLine('Accept')) as $type) {
            if ($type === 'application/json' || $type === 'text/json' || str_ends_with($type, '+json')) {
                return ErrorFormat::Json;
            }

            if ($type === 'text/html' || $type === 'application/xhtml+xml') {
                return ErrorFormat::Html;
            }
        }

        $isJsonBody = str_contains(strtolower($request->getHeaderLine('Content-Type')), 'json');
        $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';

        return $isJsonBody || $isAjax ? ErrorFormat::Json : ErrorFormat::Text;
    }

    /**
     * Media types from an Accept header, best quality first (`q=0` types are dropped).
     *
     * @return list<string>
     */
    private function accepted(string $header): array
    {
        $types = [];

        foreach (explode(',', substr($header, 0, 1000)) as $position => $part) {
            $pieces = explode(';', $part);
            $type = strtolower(trim($pieces[0]));
            $quality = 1.0;

            foreach (\array_slice($pieces, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([01](?:\.\d{0,3})?)\s*$/i', $parameter, $m) === 1) {
                    $quality = (float) $m[1];
                }
            }

            if ($type !== '' && $quality > 0.0) {
                $types[] = [$type, $quality, $position];
            }
        }

        usort($types, static fn(array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        return array_map(static fn(array $t): string => $t[0], $types);
    }
}
