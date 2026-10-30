<?php

declare(strict_types=1);

namespace Trunk\Auth\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Trunk\Auth\Auth;
use Trunk\Auth\Jwt\InvalidJwt;
use Trunk\Auth\Jwt\JwtSettings;
use Trunk\Auth\Jwt\JwtUserResolver;
use Trunk\Auth\Jwt\JwtVerifier;
use Trunk\Http\Exception\HttpException;

/**
 * Authenticates API requests with `Authorization: Bearer <JWT>` from your identity provider (Auth0,
 * Clerk, Cognito, Entra ID, Supabase...). No session, no cookies, so no CSRF. Every refusal is the
 * same 401 for the client; the reason ("expired", "wrong audience") is logged. Inside the route,
 * `Auth::user()` is the matching local user, `Auth::claims()` the token's claims, and the token's
 * scopes act as abilities (`Auth::tokenCan()`, `Gate`). Settings in config/jwt.php.
 *
 * @api
 */
final readonly class RequireJwt implements MiddlewareInterface
{
    /** @internal wired by the container */
    public function __construct(
        private Auth $auth,
        private JwtVerifier $verifier,
        private JwtUserResolver $users,
        private JwtSettings $settings,
        private LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        $token = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : null;

        if ($token === null) {
            throw self::refused();
        }

        try {
            $claims = $this->verifier->verify($token);
        } catch (InvalidJwt $e) {
            $this->logger->info('JWT refused: {reason}', ['reason' => $e->getMessage()]);

            throw self::refused('invalid_token');
        }

        $user = $this->users->resolve($claims);

        if ($user === null && $this->settings->userMatch !== 'none') {
            $this->logger->info('JWT refused: no local user for {claim} {value}', ['claim' => $this->settings->userClaim, 'value' => substr((string) json_encode($claims->get($this->settings->userClaim) ?? $claims->subject), 0, 120)]);

            throw self::refused('invalid_token');
        }

        $this->auth->authenticatedByJwt($user, $claims);

        return $handler->handle($request);
    }

    private static function refused(?string $error = null): HttpException
    {
        return new HttpException(401, 'Authentication is required.', ['WWW-Authenticate' => $error === null ? 'Bearer' : 'Bearer error="' . $error . '"']);
    }
}
