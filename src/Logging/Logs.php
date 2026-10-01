<?php

declare(strict_types=1);

namespace Trunk\Logging;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Loggers by category, so each part of an application can log at its own level. Inject `Logs` and
 * ask for a logger named after the class (or any dotted name):
 *
 *   public function __construct(Logs $logs) { $this->logger = $logs->for(self::class); }
 *
 * Its minimum level comes from `levels` in config/logging.php, where the most specific prefix wins
 * (`App\Billing` covers `App\Billing\Invoices`; `payments` covers `payments.stripe`), and anything
 * unlisted uses `level`. Every record carries `category`. Injecting LoggerInterface directly keeps
 * working as before: that is the logger with no category, at `level`.
 *
 * @api
 */
final class Logs
{
    public const string CATEGORY_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.:\\\\-]{0,199}$/D';

    private const array RANKS = [LogLevel::DEBUG => 0, LogLevel::INFO => 1, LogLevel::NOTICE => 2, LogLevel::WARNING => 3, LogLevel::ERROR => 4, LogLevel::CRITICAL => 5, LogLevel::ALERT => 6, LogLevel::EMERGENCY => 7];

    /** Categories are normally a fixed set of class names; past this many, loggers are built each time instead of kept. */
    private const int KEEP_AT_MOST = 256;

    /** @var array<string, LoggerInterface> */
    private array $loggers = [];

    /** @var array<string, string> lowercase prefix => level, longest prefix first */
    private readonly array $levels;

    /**
     * @param array<string, string> $levels category prefix => minimum level
     *
     * @internal wired by LoggingModule
     */
    public function __construct(private readonly StructuredLogger $root, private readonly string $level = LogLevel::INFO, array $levels = [])
    {
        self::rank($level);
        $sorted = [];

        foreach ($levels as $prefix => $minimum) {
            self::assertCategory($prefix);
            self::rank($minimum);
            $sorted[strtolower($prefix)] = $minimum;
        }

        uksort($sorted, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $this->levels = $sorted;
    }

    /**
     * The logger for one category, usually `self::class`.
     *
     * @throws InvalidArgumentException when the name is empty, longer than 200 characters, or has
     *                                  characters other than letters, digits and `_ . : \ -`
     */
    public function for(string $category): LoggerInterface
    {
        if (isset($this->loggers[$category])) {
            return $this->loggers[$category];
        }

        self::assertCategory($category);
        $level = $this->levelFor($category);
        $logger = $this->root->forCategory($category, $level);

        if (\count($this->loggers) < self::KEEP_AT_MOST) {
            $this->loggers[$category] = $logger;
        }

        return $logger;
    }

    /**
     * @throws InvalidArgumentException for anything but a PSR-3 level
     */
    private static function rank(string $level): int
    {
        return self::RANKS[$level] ?? throw new InvalidArgumentException(\sprintf('"%s" is not a log level. Use one of: %s.', $level, implode(', ', array_keys(self::RANKS))));
    }

    private function levelFor(string $category): string
    {
        $name = strtolower($category);

        foreach ($this->levels as $prefix => $level) {
            $prefix = (string) $prefix;

            if ($name === $prefix || str_starts_with($name, $prefix . '\\') || str_starts_with($name, $prefix . '.')) {
                return $level;
            }
        }

        return $this->level;
    }

    private static function assertCategory(string $category): void
    {
        if (preg_match(self::CATEGORY_PATTERN, $category) !== 1) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a log category: use a class name or a dotted name (letters, digits, _ . : \\ -), at most 200 characters.', substr($category, 0, 64)));
        }
    }
}
