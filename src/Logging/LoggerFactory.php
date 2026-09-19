<?php

declare(strict_types=1);

namespace Trunk\Logging;

use Psr\Log\LogLevel;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Exception\ConfigurationException;
use Trunk\Foundation\Runtime;

/**
 * Builds the application logger from the `logging.*` configuration. Missing settings fall back to
 * safe defaults (info, stderr, JSON), so an application without a logging config still logs.
 */
final readonly class LoggerFactory
{
    public const array LEVELS = [LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY];

    /**
     * Problems with the logging configuration, each with the fix (used by `trunk build`).
     *
     * @return list<string>
     */
    public static function problems(Configuration $configuration): array
    {
        $problems = [];
        $level = self::string($configuration, 'level', 'info');
        $channel = self::string($configuration, 'channel', 'stderr');
        $format = self::string($configuration, 'format', 'json');

        if (!\in_array($level, self::LEVELS, true)) {
            $problems[] = \sprintf('logging.level "%s" is not a log level. Use one of: %s (LOG_LEVEL in .env).', $level, implode(', ', self::LEVELS));
        }

        if (!\in_array($channel, ['stderr', 'file', 'null'], true)) {
            $problems[] = \sprintf('logging.channel "%s" is not supported. Use stderr, file or null (LOG_CHANNEL in .env).', $level === '' ? '' : $channel);
        }

        if (!\in_array($format, ['json', 'line'], true)) {
            $problems[] = \sprintf('logging.format "%s" is not supported. Use json or line.', $format);
        }

        if ($channel === 'file') {
            $path = self::string($configuration, 'path', '');
            $directory = $path;

            while ($directory !== '' && !is_dir($directory) && $directory !== \dirname($directory)) {
                $directory = \dirname($directory);
            }

            if ($path === '' || !is_writable($directory)) {
                $problems[] = \sprintf('The log directory "%s" is not writable. Create it or change logging.path (storage/logs by default).', $path);
            }
        }

        if ($configuration->has('logging.redact')) {
            foreach ($configuration->array('logging.redact') as $key) {
                if (!\is_string($key) || $key === '' || \strlen($key) > 64) {
                    $problems[] = 'logging.redact must be a list of non-empty key names (at most 64 characters each).';

                    break;
                }
            }
        }

        return $problems;
    }

    public function create(Configuration $configuration, Runtime $runtime, ContextHolder $context): StructuredLogger
    {
        $problems = self::problems($configuration);

        if ($problems !== []) {
            throw new ConfigurationException($problems[0]);
        }

        $channel = self::string($configuration, 'channel', 'stderr');
        $handler = match ($channel) {
            'file' => new FileHandler(self::string($configuration, 'path', $runtime->basePath . '/storage/logs')),
            'null' => new NullHandler(),
            default => new StreamHandler('php://stderr'),
        };
        $extra = $configuration->has('logging.redact') ? array_values(array_filter($configuration->array('logging.redact'), is_string(...))) : [];

        return new StructuredLogger(
            self::string($configuration, 'format', 'json') === 'line' ? new LineFormatter() : new JsonFormatter(),
            $handler,
            self::string($configuration, 'level', 'info'),
            $context,
            self::string($configuration, 'service', 'app'),
            $runtime->environment->value,
            new ContextNormalizer(new Redactor($extra)),
        );
    }

    private static function string(Configuration $configuration, string $key, string $default): string
    {
        $value = $configuration->has('logging.' . $key) ? $configuration->get('logging.' . $key) : $default;

        return \is_string($value) ? $value : $default;
    }
}
