<?php

declare(strict_types=1);

namespace Trunk\Logging;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * A PSR-3 logger that writes structured records (JSON by default), enriched with the request or
 * job ids, service name and environment, with secrets redacted and text sanitised. It never throws
 * into the application: if the handler fails, the record goes to PHP's error_log instead.
 */
final class StructuredLogger extends AbstractLogger
{
    private const array LEVELS = [LogLevel::DEBUG => 0, LogLevel::INFO => 1, LogLevel::NOTICE => 2, LogLevel::WARNING => 3, LogLevel::ERROR => 4, LogLevel::CRITICAL => 5, LogLevel::ALERT => 6, LogLevel::EMERGENCY => 7];

    private const array RESERVED = ['timestamp', 'level', 'message', 'service', 'environment'];

    private readonly int $threshold;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly LogFormatter $formatter,
        private readonly LogHandler $handler,
        string $level = LogLevel::INFO,
        private readonly ?ContextHolder $context = null,
        private readonly string $service = 'app',
        private readonly string $environment = 'production',
        private readonly ContextNormalizer $normalizer = new ContextNormalizer(),
        private readonly ?Closure $clock = null,
    ) {
        $this->threshold = self::LEVELS[$level] ?? throw new InvalidArgumentException(\sprintf('"%s" is not a PSR-3 log level.', $level));
    }

    public function isEnabled(string $level): bool
    {
        return (self::LEVELS[$level] ?? -1) >= $this->threshold;
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!\is_string($level) || !isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException('The log level must be one of the PSR-3 levels.');
        }

        if (self::LEVELS[$level] < $this->threshold) {
            return;
        }

        try {
            $this->handler->write($this->formatter->format($this->record($level, (string) $message, $context)));
        } catch (Throwable) {
            error_log('Trunk logger failure: the log record could not be written (' . $level . ').');
        }
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private function record(string $level, string $message, array $context): LogRecord
    {
        $message = $this->normalizer->text($this->interpolate($message, $context));
        $normalized = $this->normalizer->normalize($context);
        $extra = ['service' => $this->service, 'environment' => $this->environment];
        $current = $this->context?->get();

        if ($current !== null) {
            $extra += ['requestId' => $current->requestId, 'traceId' => $current->traceId, 'kind' => $current->kind];

            if ($current->originRequestId !== null) {
                $extra['originRequestId'] = $current->originRequestId;
            }
        }

        foreach ($normalized as $key => $value) {
            $extra[\in_array($key, self::RESERVED, true) ? $key . '_' : $key] = $value;
        }

        return new LogRecord($this->clock !== null ? ($this->clock)() : new DateTimeImmutable('now', new DateTimeZone('UTC')), $level, $message, $extra);
    }

    /**
     * PSR-3 placeholder interpolation: {name} is replaced by scalar or Stringable context values.
     *
     * @param array<array-key, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replace = [];

        foreach ($context as $key => $value) {
            if (\is_string($key) && (\is_scalar($value) || $value === null)) {
                $replace['{' . $key . '}'] = $this->normalizer->sensitive($key) ? Redactor::MASK : (string) $value;
            }
        }

        return strtr($message, $replace);
    }
}
