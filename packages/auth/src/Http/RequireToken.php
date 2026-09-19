<?php

declare(strict_types=1);

namespace Trunk\Auth\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Trunk\Auth\Auth;
use Trunk\Auth\Token\TokenManager;
use Trunk\Auth\User\UserProvider;
use Trunk\Http\Exception\HttpException;

/**
 * Authenticates API requests with `Authorization: Bearer <token>`. It needs no session and no
 * cookies, so it is not open to CSRF. Every failure (no header, malformed, unknown, expired, revoked,
 * owner gone, owner's session version changed since the token was issued) gives the same 401 with `WWW-Authenticate: Bearer`. Inside the route, `Auth::user()`
 * is the token's owner and `Auth::tokenCan('ability')` checks what the token may do.
 *
 * @api
 */
final class RequireToken implements MiddlewareInterface
{
    /** @internal wired by the container */
    public function __construct(private readonly Auth $auth, private readonly TokenManager $tokens, private readonly UserProvider $users) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->bearer($request);
        $verified = $token === null ? null : $this->tokens->verify($token);
        $user = $verified === null ? null : $this->users->byId($verified->userId);

        if ($verified === null || $user === null || !hash_equals($verified->userVersion, $user->authSessionVersion())) {
            throw new HttpException(401, 'Authentication is required.', ['WWW-Authenticate' => 'Bearer']);
        }

        $this->auth->authenticatedByToken($user, $verified->abilities);

        return $handler->handle($request);
    }

    private function bearer(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        return str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : null;
    }
}
