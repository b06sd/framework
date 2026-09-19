<?php

declare(strict_types=1);

namespace Trunk\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Trunk\Error\ErrorContext;
use Trunk\Error\ExceptionHandler;
use Trunk\Http\Error\ErrorFormat;
use Trunk\Http\Error\ErrorNegotiator;
use Trunk\Http\Error\ErrorRenderer;
use Trunk\Http\Error\HtmlErrorRenderer;
use Trunk\Http\Error\JsonErrorRenderer;
use Trunk\Http\Error\RenderedError;
use Trunk\Http\Error\TextErrorRenderer;
use Trunk\Http\Exception\HttpException;
use Trunk\Logging\RequestContext;

/**
 * Outermost middleware: every failure goes through the ExceptionHandler (classify, log, report)
 * and comes out as a safe response in the format the client asked for (JSON, an HTML page, or
 * plain text). Clients only ever see a PublicError's message; anything else is a generic 500 with a
 * request id. Details (class, message, location, trace) appear only when `$debug` is true, and
 * Runtime forces debug off in production. The error path itself never throws.
 */
final readonly class ErrorHandlingMiddleware implements MiddlewareInterface
{
    private ExceptionHandler $handler;

    private ErrorNegotiator $negotiator;

    /**
     * @param list<ErrorRenderer> $renderers custom renderers, tried before the built-in ones
     * @param string              $format    auto, json, html or text
     */
    public function __construct(
        private ResponseFactoryInterface $responses,
        private bool $debug = false,
        ?LoggerInterface $logger = null,
        ?ExceptionHandler $handler = null,
        private array $renderers = [],
        private string $format = 'auto',
    ) {
        $this->handler = $handler ?? new ExceptionHandler($logger);
        $this->negotiator = new ErrorNegotiator();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $e) {
            return $this->respond($e, $request);
        }
    }

    public function respond(Throwable $error, ?ServerRequestInterface $request = null): ResponseInterface
    {
        try {
            $context = $request?->getAttribute(RequestContext::class);
            $context = $context instanceof RequestContext ? $context : null;
            $report = $this->handler->handle($error, new ErrorContext($context->kind ?? 'http', $context->requestId ?? null, $context->traceId ?? null, $this->debug));
            $rendered = $this->render($report, $this->negotiator->negotiate($request, $this->format), $request);
            $response = $this->responses->createResponse($report->status)
                ->withHeader('Content-Type', $rendered->contentType)
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', 'no-store');

            if ($report->requestId !== null) {
                $response = $response->withHeader('X-Request-Id', $report->requestId);
            }

            foreach ([...($error instanceof HttpException ? $error->headers : []), ...$rendered->headers] as $name => $value) {
                $response = $response->withHeader($name, $value);
            }

            $response->getBody()->write($rendered->body);

            return $response;
        } catch (Throwable) {
            // The error path itself must never throw.
            return $this->responses->createResponse(500);
        }
    }

    private function render(\Trunk\Error\ErrorReport $report, ErrorFormat $format, ?ServerRequestInterface $request): RenderedError
    {
        foreach ([...$this->renderers, new JsonErrorRenderer(), new HtmlErrorRenderer()] as $renderer) {
            try {
                $rendered = $renderer->render($report, $format, $request, $this->debug);
            } catch (Throwable) {
                continue;
            }

            if ($rendered !== null) {
                return $rendered;
            }
        }

        return new TextErrorRenderer()->render($report, $format, $request, $this->debug);
    }
}
