<?php

declare(strict_types=1);

namespace Trunk\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Trunk\Foundation\Configuration;
use Trunk\Http\Response\ResponseBuilder;
use Trunk\Http\Security\Cors;

/**
 * Applies the `cors` policy from config/http.php to every request, global middleware registered by
 * `HttpModule` so it can answer a preflight before routing ever runs: the router has no way to match
 * an `OPTIONS` request for a route that was only ever declared for `GET`/`POST`/etc., so a preflight
 * to a real route would otherwise be a 404 or 405. Does nothing when `cors.enabled` is not true.
 *
 * @api
 */
final readonly class CorsMiddleware implements MiddlewareInterface
{
    private ?Cors $cors;

    /** @internal wired by the container */
    public function __construct(Configuration $configuration, private ResponseBuilder $responses)
    {
        $this->cors = Cors::fromConfiguration($configuration);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->cors === null) {
            return $handler->handle($request);
        }

        $origin = $request->getHeaderLine('Origin');

        if (!$this->cors->allows($origin)) {
            // Still marked: a shared cache must not hand this answer (without CORS headers) to an allowed origin.
            $response = $handler->handle($request);

            return $this->cors->variesByOrigin() ? $this->withHeaders($response, ['Vary' => 'Origin']) : $response;
        }

        if ($request->getMethod() === 'OPTIONS' && $request->getHeaderLine('Access-Control-Request-Method') !== '') {
            return $this->withHeaders($this->responses->noContent(), $this->cors->preflightHeaders($origin, $this->requestedHeaders($request)));
        }

        return $this->withHeaders($handler->handle($request), $this->cors->responseHeaders($origin));
    }

    private function requestedHeaders(ServerRequestInterface $request): ?string
    {
        $requested = $request->getHeaderLine('Access-Control-Request-Headers');

        return $requested === '' ? null : $requested;
    }

    /**
     * Sets the CORS headers. `Vary` is merged into what the response already varies by (a session's
     * `Vary: Cookie`, say), never replaced, since dropping a Vary lets a cache serve one client's
     * response to another.
     *
     * @param array<string, string> $headers
     */
    private function withHeaders(ResponseInterface $response, array $headers): ResponseInterface
    {
        foreach ($headers as $name => $value) {
            $response = $name === 'Vary' ? $this->withVary($response, $value) : $response->withHeader($name, $value);
        }

        return $response;
    }

    private function withVary(ResponseInterface $response, string $fields): ResponseInterface
    {
        $merged = [];

        foreach ([...$response->getHeader('Vary'), $fields] as $line) {
            foreach (explode(',', $line) as $field) {
                $field = trim($field);

                if ($field !== '') {
                    $merged[strtolower($field)] ??= $field;
                }
            }
        }

        // `*` already means "varies by everything"; anything added to it is noise.
        return $response->withHeader('Vary', isset($merged['*']) ? '*' : implode(', ', $merged));
    }
}
