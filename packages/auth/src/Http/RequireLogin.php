<?php

declare(strict_types=1);

namespace Trunk\Auth\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Trunk\Auth\Auth;
use Trunk\Auth\Settings\AuthSettings;
use Trunk\Http\Exception\HttpException;
use Trunk\Http\Response\ResponseBuilder;

/**
 * Lets only signed-in users through. Browsers asking for a page are sent to `auth.login_path` (and
 * back afterwards, see `Auth::intended()`); everything else, including every API client, gets a plain
 * 401. Put it after `SessionMiddleware`.
 *
 * @api
 */
final class RequireLogin implements MiddlewareInterface
{
    /** @internal wired by the container */
    public function __construct(private readonly Auth $auth, private readonly AuthSettings $settings, private readonly ResponseBuilder $responses) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->auth->check()) {
            return $handler->handle($request);
        }

        if ($this->isBrowserPageRequest($request)) {
            $uri = $request->getUri();
            $this->auth->rememberIntended($uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery()));

            return $this->responses->redirect($this->settings->loginPath)->withHeader('Cache-Control', 'no-store');
        }

        throw new HttpException(401, 'Authentication is required.');
    }

    private function isBrowserPageRequest(ServerRequestInterface $request): bool
    {
        return \in_array($request->getMethod(), ['GET', 'HEAD'], true) && str_contains($request->getHeaderLine('Accept'), 'text/html');
    }
}
