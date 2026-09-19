<?php

declare(strict_types=1);

namespace Trunk\Console\Process;

/**
 * Starts a child process from an argument array (never a shell string) and waits for it.
 */
interface ProcessLauncher
{
    /**
     * @param non-empty-list<string> $argv
     * @param array<string, string>  $environment
     *
     * @return int the exit code
     */
    public function launch(array $argv, string $workingDirectory, array $environment): int;
}
