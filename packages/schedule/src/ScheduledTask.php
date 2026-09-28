<?php

declare(strict_types=1);

namespace Trunk\Schedule;

use DateTimeImmutable;
use Trunk\Queue\Job\Job;

/**
 * One entry in app/Schedule.php, returned by Scheduler::job()/command() already registered; the
 * fluent frequency methods set when it runs and return $this, the same shape as MapBuilder's
 * column specs.
 *
 * @api
 */
final class ScheduledTask
{
    private Frequency $frequency;

    public function __construct(
        public readonly ?Job $job = null,
        public readonly ?string $command = null,
    ) {
        $this->frequency = Frequency::everyMinutes(1);
    }

    public function everyMinutes(int $minutes): static
    {
        $this->frequency = Frequency::everyMinutes($minutes);

        return $this;
    }

    public function hourly(): static
    {
        $this->frequency = Frequency::hourly();

        return $this;
    }

    public function daily(string $at = '00:00'): static
    {
        $this->frequency = Frequency::daily($at);

        return $this;
    }

    /** $dayOfWeek matches DateTimeImmutable::format('w'): 0 (Sunday) to 6 (Saturday). */
    public function weekly(int $dayOfWeek, string $at = '00:00'): static
    {
        $this->frequency = Frequency::weekly($dayOfWeek, $at);

        return $this;
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        return $this->frequency->isDue($now);
    }

    public function describe(): string
    {
        return $this->frequency->describe();
    }

    /** What to call it in schedule:list: the job's class name, or the command. */
    public function label(): string
    {
        return $this->job !== null ? $this->job::class : (string) $this->command;
    }
}
