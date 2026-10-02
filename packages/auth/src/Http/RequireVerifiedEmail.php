<?php

declare(strict_types=1);

namespace Trunk\Auth\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Trunk\Auth\Auth;
use Trunk\Auth\Settings\AuthSettings;
use Trunk\Auth\Verification\EmailVerification;
use Trunk\Http\Exception\HttpException;
use Trunk\Http\Response\ResponseBuilder;

/**
 * Lets only users with a verified email address through. Browsers asking for a page are sent to
 * `auth.verify_path`; API clients get a 403 with the code `EMAIL_NOT_VERIFIED`. Put it after
 * `RequireLogin` (or `RequireToken`); a request with nobody signed in is a 401.
 *
 * @api
 */
final class RequireVerifiedEmail implements MiddlewareInterface
{
    /** @internal wired by the container */
    public function __construct(private readonly Auth $auth, private readonly EmailVerification $verification, private readonly AuthSettings $settings, private readonly ResponseBuilder $responses) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->auth->user();

        if ($user === null) {
            throw new HttpException(401, 'Authentication is required.');
        }

        if ($this->verification->isVerified($user)) {
            return $handler->handle($request);
        }

        if (\in_array($request->getMethod(), ['GET', 'HEAD'], true) && str_contains($request->getHeaderLine('Accept'), 'text/html')) {
            return $this->responses->redirect($this->settings->verifyPath)->withHeader('Cache-Control', 'no-store');
        }

        throw new HttpException(403, 'The email address is not verified.', code: 'EMAIL_NOT_VERIFIED');
    }
}
