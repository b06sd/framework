<?php

declare(strict_types=1);

namespace Trunk\Console\Process;

use Trunk\Contracts\Console\Exception\CommandFailedException;

/**
 * The only place in Trunk that starts a child process. It takes an argument array, so no shell is
 * involved and no text is ever interpreted; the callers (ProcessRunner) use fixed executables.
 * The security test suite allowlists exactly this file.
 */
final class ProcOpenLauncher implements ProcessLauncher
{
    public function launch(array $argv, string $workingDirectory, array $environment): int
    {
        $process = proc_open($argv, [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR], $pipes, $workingDirectory, $environment);

        if (!\is_resource($process)) {
            throw new CommandFailedException('Unable to start the process.');
        }

        return proc_close($process);
    }
}
