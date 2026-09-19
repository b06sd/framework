<?php

declare(strict_types=1);

namespace Trunk\Auth\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Trunk\Auth\Csrf\CsrfTokens;
use Trunk\Auth\Session\Session;
use Trunk\Error\ErrorCode;
use Trunk\Http\Exception\HttpException;

/**
 * Rejects cross-site request forgery on routes that use cookies. Every request except GET, HEAD and
 * OPTIONS must carry the session's token (header `X-CSRF-Token` or form field `_csrf`) and, when the
 * browser sends them, a same-origin `Origin` and `Sec-Fetch-Site`. Put it after `SessionMiddleware`.
 * Stateless API routes that authenticate with bearer tokens do not need it (and do not use cookies).
 *
 * @api
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    /** @internal wired by the container */
    public function __construct(private readonly Session $session, private readonly CsrfTokens $tokens) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (\in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        if (!$this->sameOrigin($request) || !$this->tokens->verify($this->session, $this->submitted($request))) {
            throw new HttpException(403, 'The request was refused. Reload the page and try again.', [], null, ErrorCode::CsrfTokenInvalid->value);
        }

        return $handler->handle($request);
    }

    private function submitted(ServerRequestInterface $request): string
    {
        $header = $request->getHeaderLine('X-CSRF-Token');

        if ($header !== '') {
            return $header;
        }

        $body = $request->getParsedBody();
        $field = \is_array($body) ? ($body['_csrf'] ?? null) : null;

        return \is_string($field) ? $field : '';
    }

    /**
     * Browsers state where a request came from. A cross-site value refuses the request even before the
     * token is looked at; a missing value (older browsers, non-browser clients) leaves the token as
     * the deciding check.
     */
    private function sameOrigin(ServerRequestInterface $request): bool
    {
        $site = $request->getHeaderLine('Sec-Fetch-Site');

        if ($site !== '' && !\in_array($site, ['same-origin', 'none'], true)) {
            return false;
        }

        if (!$request->hasHeader('Origin')) {
            return true;
        }

        $uri = $request->getUri();
        $port = $uri->getPort();
        $expected = $uri->getScheme() . '://' . $uri->getHost() . ($port === null ? '' : ':' . $port);

        return strtolower($request->getHeaderLine('Origin')) === strtolower($expected);
    }
}
