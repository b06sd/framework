<?php

declare(strict_types=1);

namespace Trunk\Http\Routing;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Trunk\Error\ErrorCode;
use Trunk\Http\Exception\HttpException;
use Trunk\Http\Kernel\RequestState;
use Trunk\Router\Matcher\Matcher;
use Trunk\Router\Matcher\MatchStatus;

/**
 * Matches the request and stores the result as the `trunk.route` attribute; unmatched requests
 * become 404 or 405 (with Allow) HttpExceptions for the error handler to render.
 */
final readonly class RoutingMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'trunk.route';

    public function __construct(private Matcher $matcher) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = $this->matcher->matchRequest($request);

        return match ($result->status) {
            MatchStatus::Found => $handler->handle($this->remember($request, $result)),
            MatchStatus::MethodNotAllowed => throw HttpException::methodNotAllowed($result->allowedMethods ?: ['GET']),
            MatchStatus::NotFound => throw HttpException::notFound('', ErrorCode::RouteNotFound->value),
        };
    }

    private function remember(ServerRequestInterface $request, \Trunk\Router\Matcher\MatchResult $result): ServerRequestInterface
    {
        $state = $request->getAttribute(RequestState::class);

        if ($state instanceof RequestState) {
            $handler = $result->handler;
            $state->route = $result->name ?? ($handler === null ? null : substr(strrchr('\\' . $handler[0], '\\') ?: $handler[0], 1) . '::' . $handler[1]);
        }

        return $request->withAttribute(self::ATTRIBUTE, $result);
    }
}
