<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Trunk\Console\Process\ProcessLauncher;

final class RecordingLauncher implements ProcessLauncher
{
    /** @var list<string> */
    public array $argv = [];

    /** @var array<string, string> */
    public array $environment = [];

    public function launch(array $argv, string $workingDirectory, array $environment): int
    {
        $this->argv = $argv;
        $this->environment = $environment;

        return 0;
    }
}
