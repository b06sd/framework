<?php

declare(strict_types=1);

namespace Trunk\Schedule;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * How often a task runs, and whether a given moment is due. Everything is interpreted in UTC and
 * at minute resolution (schedule:run is meant to be invoked once a minute); there is deliberately
 * no cron-string parsing.
 *
 * @internal
 */
final readonly class Frequency
{
    private const array WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    private function __construct(
        private FrequencyKind $kind,
        private int $every = 1,
        private int $hour = 0,
        private int $minute = 0,
        private int $dayOfWeek = 0,
    ) {}

    public static function everyMinutes(int $minutes): self
    {
        if ($minutes < 1) {
            throw new InvalidArgumentException('everyMinutes() needs a number of one or more.');
        }

        return new self(FrequencyKind::Minutes, every: $minutes);
    }

    public static function hourly(): self
    {
        return new self(FrequencyKind::Hourly);
    }

    public static function daily(string $at): self
    {
        [$hour, $minute] = self::time($at);

        return new self(FrequencyKind::Daily, hour: $hour, minute: $minute);
    }

    /** $dayOfWeek matches DateTimeImmutable::format('w'): 0 (Sunday) to 6 (Saturday). */
    public static function weekly(int $dayOfWeek, string $at): self
    {
        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            throw new InvalidArgumentException('weekly() needs a day of week between 0 (Sunday) and 6 (Saturday).');
        }

        [$hour, $minute] = self::time($at);

        return new self(FrequencyKind::Weekly, hour: $hour, minute: $minute, dayOfWeek: $dayOfWeek);
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        return match ($this->kind) {
            FrequencyKind::Minutes => intdiv($now->getTimestamp(), 60) % $this->every === 0,
            FrequencyKind::Hourly => (int) $now->format('i') === 0,
            FrequencyKind::Daily => $this->atTime($now),
            FrequencyKind::Weekly => (int) $now->format('w') === $this->dayOfWeek && $this->atTime($now),
        };
    }

    public function describe(): string
    {
        $at = \sprintf('%02d:%02d', $this->hour, $this->minute);

        return match ($this->kind) {
            FrequencyKind::Minutes => $this->every === 1 ? 'every minute' : 'every ' . $this->every . ' minutes',
            FrequencyKind::Hourly => 'hourly, on the hour',
            FrequencyKind::Daily => 'daily at ' . $at . ' UTC',
            FrequencyKind::Weekly => self::WEEKDAYS[$this->dayOfWeek] . ' at ' . $at . ' UTC',
        };
    }

    private function atTime(DateTimeImmutable $now): bool
    {
        return (int) $now->format('H') === $this->hour && (int) $now->format('i') === $this->minute;
    }

    /**
     * @return array{int, int}
     */
    private static function time(string $at): array
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D', $at, $matches) !== 1) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a time of day in 24-hour HH:MM, e.g. "02:30".', $at));
        }

        return [(int) $matches[1], (int) $matches[2]];
    }
}
