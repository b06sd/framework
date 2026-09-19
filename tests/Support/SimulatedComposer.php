<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Closure;
use Trunk\Console\Process\ProcessLauncher;

/**
 * A ProcessLauncher standing in for Composer: it records the argument arrays it is given and can
 * run a callback (for example to fake what `composer require` installs).
 */
final class SimulatedComposer implements ProcessLauncher
{
    /** @var list<list<string>> */
    public array $calls = [];

    /**
     * @param (Closure(list<string>): void)|null $onLaunch
     */
    public function __construct(private readonly int $exitCode = 0, private readonly ?Closure $onLaunch = null) {}

    public function launch(array $argv, string $workingDirectory, array $environment): int
    {
        $this->calls[] = $argv;

        if ($this->onLaunch !== null && $this->exitCode === 0) {
            ($this->onLaunch)($argv);
        }

        return $this->exitCode;
    }
}
