<?php

declare(strict_types=1);

namespace Trunk\OpenApi\Http;

use Psr\Http\Message\ResponseInterface;
use Trunk\Http\Exception\HttpException;
use Trunk\Http\Response\ResponseBuilder;
use Trunk\OpenApi\OpenApi;

/**
 * GET /openapi.json and GET /docs (Swagger UI). Both answer 404 unless `openapi.serve` is on, which
 * by default it is only in local development.
 *
 * @internal
 */
final readonly class OpenApiController
{
    /** Swagger UI, pinned and checked by Subresource Integrity, so a changed file on the CDN never runs. */
    private const string ASSETS = 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.33.3';

    private const string SCRIPT_INTEGRITY = 'sha384-h00llMzD1afyNow1pDmnZKlHsUZgp9KE9HXLFXF2CvbwsBY0+WBO4f75SfGwvM4K';

    private const string STYLE_INTEGRITY = 'sha384-Ov4/wv3j2bmct8cDc5X4ngJZohVPzEmc6uDPH8WeljUxO5vtoykvMEfbu9Vh6RaW';

    private const string START = "window.addEventListener('load', function () { window.ui = SwaggerUIBundle({ url: 'openapi.json', dom_id: '#swagger-ui', deepLinking: true }); });";

    public function __construct(private OpenApi $openApi, private ResponseBuilder $responses, private bool $serve) {}

    public function spec(): ResponseInterface
    {
        $this->guard();

        return $this->responses->json($this->openApi->document())->withHeader('Cache-Control', 'no-store');
    }

    public function ui(): ResponseInterface
    {
        $this->guard();

        $info = $this->openApi->document()['info'] ?? null;
        $title = htmlspecialchars(\is_array($info) && \is_string($info['title'] ?? null) ? $info['title'] : 'API', \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $title . '</title><link rel="icon" href="data:,">'
            . '<link rel="stylesheet" href="' . self::ASSETS . '/swagger-ui.css" integrity="' . self::STYLE_INTEGRITY . '" crossorigin="anonymous">'
            . '</head><body><div id="swagger-ui"></div>'
            . '<script src="' . self::ASSETS . '/swagger-ui-bundle.js" integrity="' . self::SCRIPT_INTEGRITY . '" crossorigin="anonymous"></script>'
            . '<script>' . self::START . '</script></body></html>';

        return $this->responses->html($html)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Content-Security-Policy', self::policy());
    }

    /**
     * Scripts only from the pinned CDN files and the one inline start-up script (by hash); requests
     * ("Try it out") only to this site.
     */
    public static function policy(): string
    {
        return "default-src 'none'; script-src https://cdn.jsdelivr.net 'sha256-" . base64_encode(hash('sha256', self::START, true)) . "'; "
            . "style-src https://cdn.jsdelivr.net 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; "
            . "frame-ancestors 'none'; base-uri 'none'; form-action 'none'";
    }

    private function guard(): void
    {
        if (!$this->serve) {
            throw HttpException::notFound();
        }
    }
}
