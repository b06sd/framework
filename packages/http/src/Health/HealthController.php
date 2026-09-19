<?php

declare(strict_types=1);

namespace Trunk\Http\Health;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Trunk\Health\HealthChecker;
use Trunk\Http\Exception\HttpException;
use Trunk\Http\Response\ResponseBuilder;
use Trunk\Observability\MetricsExporter;

/**
 * Liveness, readiness and (optionally) metrics endpoints.
 *
 * - /health/live: the process answers. No dependencies are touched, so an orchestrator never
 *   restarts a healthy process because the database is down.
 * - /health/ready: every registered health check. 200 or 503. The body names each check and says
 *   up or down, nothing more; reasons stay in the log, and appear here only in development.
 * - /metrics: off unless a token is configured, then requires `Authorization: Bearer <token>`.
 */
final readonly class HealthController
{
    public function __construct(
        private HealthChecker $checker,
        private ResponseBuilder $responses,
        private MetricsExporter $metrics,
        private string $metricsToken,
        private bool $debug,
    ) {}

    public function live(): ResponseInterface
    {
        return $this->noStore($this->responses->json(['status' => 'ok']));
    }

    public function ready(): ResponseInterface
    {
        $report = $this->checker->run();
        $checks = [];

        foreach ($report->results as $name => $result) {
            $checks[$name] = $result->up ? 'up' : 'down';

            if ($this->debug && !$result->up && $result->detail !== null) {
                $checks[$name . '_detail'] = $result->detail;
            }
        }

        return $this->noStore($this->responses->json(['status' => $report->up() ? 'ok' : 'unavailable', 'checks' => $checks], $report->up() ? 200 : 503));
    }

    public function metrics(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->metricsToken === '' || !$this->metrics->enabled()) {
            throw HttpException::notFound();
        }

        $header = $request->getHeaderLine('Authorization');
        $given = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';

        if (!hash_equals($this->metricsToken, $given)) {
            throw new HttpException(401, 'Metrics token required.', ['WWW-Authenticate' => 'Bearer']);
        }

        return $this->noStore($this->responses->text($this->metrics->export())->withHeader('Content-Type', $this->metrics->contentType()));
    }

    private function noStore(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'no-store');
    }
}
