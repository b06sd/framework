<?php

declare(strict_types=1);

namespace Trunk\Http\Kernel;

use Closure;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Trunk\Container\Scopable;
use Trunk\Contracts\Kernel;
use Trunk\Http\Emitter\ResponseEmitter;
use Trunk\Http\Exception\HttpException;
use Trunk\Http\Middleware\ErrorHandlingMiddleware;
use Trunk\Http\Security\SecurityHeaders;
use Trunk\Http\Server\ServerRequestCreator;
use Trunk\Lifecycle\LifecycleManager;
use Trunk\Logging\ContextHolder;
use Trunk\Logging\RequestContext;
use Trunk\Logging\RequestId;
use Trunk\Logging\TraceParent;
use Trunk\Observability\Metrics;
use Trunk\Observability\Tracer;

/**
 * Entry point for web requests: request in, response out, then emit.
 *
 * Pipeline order is: global middleware, error handling, routing, controller. Error responses
 * therefore pass back through global middleware (security headers, CORS) like any other response.
 */
final readonly class HttpKernel implements Kernel
{
    /**
     * @param Closure(ContainerInterface): RequestHandlerInterface $pipelineFor builds the handler chain for one request's scope
     */
    public function __construct(
        private Scopable $container,
        private Closure $pipelineFor,
        private ErrorHandlingMiddleware $errors,
        private ResponseEmitter $emitter,
        private ServerRequestCreator $creator,
        private ?ContextHolder $contexts = null,
        private ?LifecycleManager $lifecycle = null,
        private bool $trustInboundIds = true,
        private ?Metrics $metrics = null,
        private ?Tracer $tracer = null,
        private ?SecurityHeaders $security = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->contextFor($request);
        $state = new RequestState();
        $request = $request->withAttribute(RequestContext::class, $context)->withAttribute(RequestState::class, $state);
        $this->contexts?->set($context);
        $started = hrtime(true);
        $span = $this->tracer?->startSpan('http.request', ['http.method' => $request->getMethod()]);

        try {
            // One scope per request: scoped services live exactly as long as this request.
            $scope = $this->container->beginScope([ServerRequestInterface::class => $request, RequestContext::class => $context]);

            $response = ($this->pipelineFor)($scope)->handle($request);
        } catch (Throwable $e) {
            // Failures in user middleware sit outside the error-handling layer; catch them here.
            $response = $this->errors->respond($e, $request);
        }

        // The span and metrics are finished while the request context is still current, so the span
        // record carries the request id; cleanup then clears the context for the next request.
        try {
            $this->finish($request->getMethod(), $response->getStatusCode(), $state->route, $started, $span);
        } finally {
            $this->lifecycle?->cleanup();
            $this->contexts?->reset();
        }

        if ($this->security !== null) {
            $response = $this->security->apply($response, $request->getUri()->getScheme() === 'https');
        }

        return $response->withHeader('X-Request-Id', $context->requestId);
    }

    public function send(ServerRequestInterface $request): void
    {
        $this->emitter->emit($this->handle($request), $request->getMethod() !== 'HEAD');
    }

    /**
     * Builds the request from PHP's superglobals (the single place that happens), handles and emits it.
     */
    public function run(): void
    {
        try {
            $request = $this->creator->fromGlobals();
        } catch (HttpException $e) {
            $this->emitter->emit($this->errors->respond($e));

            return;
        } catch (InvalidArgumentException $e) {
            $this->emitter->emit($this->errors->respond(HttpException::badRequest($e->getMessage())));

            return;
        }

        $this->send($request);
    }

    private function finish(string $method, int $status, ?string $route, int|float $started, ?\Trunk\Observability\Span $span): void
    {
        try {
            $label = \in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true) ? $method : 'OTHER';
            $route ??= 'unmatched';
            $labels = ['method' => $label, 'route' => $route];
            $this->metrics?->counter('http_requests_total', [...$labels, 'status_class' => intdiv($status, 100) . 'xx']);
            $this->metrics?->observe('http_request_duration_seconds', (hrtime(true) - $started) / 1e9, $labels);
            $span?->setAttribute('http.status_code', $status);
            $span?->setAttribute('http.route', $route);

            if ($status >= 500) {
                $span?->setAttribute('error', true);
            }

            $span?->end();
        } catch (Throwable) {
            // Observability must never fail a request.
        }
    }

    /**
     * The correlation ids for this request. Inbound ids are used only when they match a strict,
     * log-safe pattern; otherwise fresh ones are generated.
     */
    private function contextFor(ServerRequestInterface $request): RequestContext
    {
        $requestId = $this->trustInboundIds ? RequestId::accept($request->getHeaderLine('X-Request-Id') ?: null) : null;
        $trace = $this->trustInboundIds ? TraceParent::parse($request->getHeaderLine('traceparent') ?: null) : null;

        return new RequestContext($requestId ?? RequestId::generate(), $trace['traceId'] ?? TraceParent::newTraceId(), $trace['spanId'] ?? TraceParent::newSpanId(), 'http');
    }
}
