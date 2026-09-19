<?php

declare(strict_types=1);

namespace Trunk\Queue\Worker;

use Closure;
use Trunk\Queue\Exception\JobTimedOut;

/**
 * The worker's link to the operating system: graceful shutdown on SIGTERM/SIGINT and a hard
 * per-job timeout on SIGALRM. Both need ext-pcntl; without it the worker still runs, but it
 * cannot be told to stop between jobs by a signal and cannot interrupt a hung job.
 */
final class Signals
{
    public function available(): bool
    {
        return \function_exists('pcntl_async_signals') && \function_exists('pcntl_alarm');
    }

    /**
     * @param Closure(): void $onStop
     */
    public function onStop(Closure $onStop): void
    {
        if (!$this->available()) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(\SIGTERM, static function () use ($onStop): void {
            $onStop();
        });
        pcntl_signal(\SIGINT, static function () use ($onStop): void {
            $onStop();
        });
    }

    /**
     * Runs `$work`, raising JobTimedOut inside it if it is still running after `$seconds`.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public function withTimeout(int $seconds, Closure $work): mixed
    {
        if (!$this->available()) {
            return $work();
        }

        pcntl_async_signals(true);
        pcntl_signal(\SIGALRM, static function () use ($seconds): never {
            throw JobTimedOut::after($seconds);
        });
        pcntl_alarm($seconds);

        try {
            return $work();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(\SIGALRM, \SIG_DFL);
        }
    }
}
