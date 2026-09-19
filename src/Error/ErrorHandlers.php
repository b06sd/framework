<?php

declare(strict_types=1);

namespace Trunk\Error;

use ErrorException;
use Psr\Log\LoggerInterface;
use Throwable;
use Trunk\Logging\ContextHolder;
use Trunk\Logging\Sanitizer;

/**
 * PHP-level failure handling for one process: warnings and notices, uncaught exceptions, and fatal
 * errors including memory exhaustion. Entry points (the web front controller, the console) call
 * register(); libraries never do.
 *
 * - Warnings/notices become ErrorException in development (so they are noticed) and are logged and
 *   survived in production. Deprecations are logged, never thrown.
 * - An uncaught exception is handled like any other (logged, reported) and ends the process with a
 *   generic message and exit status 255.
 * - A fatal error writes one emergency log line and, for web requests that have not started their
 *   response, a generic 500. Memory exhaustion leaves almost no room: the shutdown handler frees a
 *   reserved buffer first, allocates almost nothing, and never throws. It cannot recover the
 *   process; the point is to leave enough evidence to diagnose it.
 */
final class ErrorHandlers
{
    private const int FATAL = \E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR;

    private ?string $reserved = null;

    private bool $registered = false;

    private ?LoggerInterface $logger = null;

    private ?ExceptionHandler $handler = null;

    private ?ContextHolder $context = null;

    /**
     * @param string $kind `http`, `cli` or `job`
     */
    public function __construct(
        private readonly EmergencyLog $emergency,
        private bool $debug = false,
        private readonly string $kind = 'http',
    ) {}

    /**
     * Supplies the application's services once the container exists. Until then only the
     * emergency log is available.
     */
    public function attach(?LoggerInterface $logger, ?ExceptionHandler $handler, ?ContextHolder $context, ?bool $debug = null): void
    {
        $this->debug = $debug ?? $this->debug;
        $this->logger = $logger;
        $this->handler = $handler;
        $this->context = $context;
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;
        $this->reserved = str_repeat('x', 32768);
        set_error_handler($this->onError(...));
        set_exception_handler($this->onException(...));
        register_shutdown_function($this->onShutdown(...));
    }

    public function onError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $level) === 0) {
            return false;
        }

        if (($level & (\E_DEPRECATED | \E_USER_DEPRECATED)) !== 0 || !$this->debug) {
            $this->log($level, $message, $file, $line);

            return true;
        }

        throw new ErrorException($message, 0, $level, $file, $line);
    }

    public function onException(Throwable $error): void
    {
        try {
            $report = ($this->handler ?? new ExceptionHandler($this->logger))->handle($error, new ErrorContext($this->kind, $this->context?->get()?->requestId, $this->context?->get()?->traceId, $this->debug));
            $this->respond($report->requestId);
        } catch (Throwable) {
            $this->emergency->write('Uncaught exception; the error handler failed too.', ['exception' => $error::class, 'kind' => $this->kind]);
            $this->respond(null);
        }

        exit(255);
    }

    public function onShutdown(): void
    {
        $freed = \strlen($this->reserved ?? '');
        $this->reserved = null;
        $last = error_get_last();

        if ($last === null || ($last['type'] & self::FATAL) === 0) {
            return;
        }

        $this->emergency->write('Fatal error.', [
            'kind' => $this->kind,
            'type' => $last['type'],
            'error' => str_starts_with($last['message'], 'Allowed memory size') ? 'memory limit exhausted' : Sanitizer::text($last['message'], 300),
            'file' => $last['file'],
            'line' => $last['line'],
            'requestId' => $this->context?->get()?->requestId,
            'memory_mb' => round(memory_get_usage(true) / 1048576, 1),
            'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'reserve_freed_bytes' => $freed,
        ]);
        $this->respond($this->context?->get()?->requestId);
    }

    /**
     * A generic answer that reveals nothing: an HTTP 500 for web requests that have not begun a
     * response, a one-line message on stderr for the console and workers.
     */
    private function respond(?string $requestId): void
    {
        if ($this->kind === 'http') {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                header('Cache-Control: no-store');

                if ($requestId !== null) {
                    header('X-Request-Id: ' . $requestId);
                }
            }

            echo 'Internal Server Error' . ($requestId !== null ? "\nRequest ID: " . $requestId : '');

            return;
        }

        fwrite(\STDERR, 'An unexpected error occurred.' . ($requestId !== null ? ' Request ID: ' . $requestId : '') . "\n");
    }

    private function log(int $level, string $message, string $file, int $line): void
    {
        try {
            $severity = ($level & (\E_DEPRECATED | \E_USER_DEPRECATED)) !== 0 ? 'notice' : 'warning';
            if ($this->logger === null) {
                error_log('PHP ' . $this->name($level) . ': ' . Sanitizer::text($message, 500) . ' in ' . Sanitizer::text($file, 300) . ':' . $line);

                return;
            }

            $this->logger->log($severity, 'PHP {type}: {message}', ['type' => $this->name($level), 'message' => $message, 'file' => $file, 'line' => $line]);
        } catch (Throwable) {
            // Logging a warning must never cause a failure of its own.
        }
    }

    private function name(int $level): string
    {
        return match (true) {
            ($level & (\E_WARNING | \E_USER_WARNING | \E_CORE_WARNING | \E_COMPILE_WARNING)) !== 0 => 'warning',
            ($level & (\E_NOTICE | \E_USER_NOTICE)) !== 0 => 'notice',
            ($level & (\E_DEPRECATED | \E_USER_DEPRECATED)) !== 0 => 'deprecated',
            default => 'error',
        };
    }
}
