<?php

declare(strict_types=1);

namespace Trunk\Error;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * The one place every failure goes through, for HTTP, CLI and workers: classify, log, report,
 * transform. Rendering is left to the caller (a JSON response, a Tusk page, a terminal message).
 *
 * The rule that keeps production safe: only a PublicError is described to the client; any other
 * exception becomes INTERNAL_ERROR with a request id. This class never throws.
 */
final readonly class ExceptionHandler
{
    /**
     * @param iterable<ErrorReporter> $reporters
     */
    public function __construct(
        private ?LoggerInterface $logger = null,
        private iterable $reporters = [],
        private DebugInfoFactory $debugInfo = new DebugInfoFactory(),
    ) {}

    public function handle(Throwable $error, ErrorContext $context = new ErrorContext()): ErrorReport
    {
        $report = $this->classify($error, $context);
        $this->log($error, $report, $context);
        $this->notify($error, $report);

        return $report;
    }

    private function classify(Throwable $error, ErrorContext $context): ErrorReport
    {
        $internalCode = $error instanceof HasErrorCode ? $error->errorCode() : null;
        $debug = null;

        if ($context->debug) {
            try {
                $debug = $this->debugInfo->create($error);
            } catch (Throwable) {
                $debug = null;
            }
        }

        if ($error instanceof PublicError) {
            $status = $error->statusCode();

            return new ErrorReport($status, $error->errorCode(), $error->publicMessage(), $context->requestId, true, $status >= 500 ? LogLevel::ERROR : LogLevel::NOTICE, $internalCode, $error->details(), $error, $debug);
        }

        return new ErrorReport(500, ErrorCode::InternalError->value, 'An unexpected error occurred.', $context->requestId, false, LogLevel::ERROR, $internalCode, [], $error, $debug);
    }

    private function log(Throwable $error, ErrorReport $report, ErrorContext $context): void
    {
        try {
            $this->logger?->log($report->level, $report->public ? 'Request failed with a public error.' : ($context->kind === 'http' ? 'Unhandled exception while handling the request.' : 'Unhandled exception.'), [
                'exception' => $error,
                'errorCode' => $report->internalCode ?? $report->code,
                'status' => $report->status,
                'kind' => $context->kind,
                'requestId' => $context->requestId,
                'traceId' => $context->traceId,
            ]);
        } catch (Throwable) {
            // A failing logger must not turn one error into another.
        }
    }

    private function notify(Throwable $error, ErrorReport $report): void
    {
        foreach ($this->reporters as $reporter) {
            try {
                $reporter->report($error, $report);
            } catch (Throwable) {
                // Reporters are isolated from each other and from the application.
            }
        }
    }
}
