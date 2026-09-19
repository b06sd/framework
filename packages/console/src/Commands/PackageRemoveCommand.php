<?php

declare(strict_types=1);

namespace Trunk\Console\Commands;

use Trunk\Console\Process\ProcessRunner;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Capability\CapabilityManager;
use Trunk\Foundation\Project\Project;

final readonly class PackageRemoveCommand implements Command
{
    public function __construct(
        private Project $project,
        private CapabilityManager $capabilities,
        private ProcessRunner $processes,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'package:remove',
            'Disable a capability (and, with --purge, uninstall its Composer package)',
            ['capability' => 'a capability id or the Composer package that provides it'],
            ['--purge' => 'also run `composer remove` for an external package'],
            1,
        );
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $target = (string) $input->argument(0);
        $catalog = $this->capabilities->catalog($this->project);
        $capability = $this->processes->isPackageSpec($target) ? $catalog->forPackage($this->processes->packageName($target)) : $catalog->find($target);

        if ($capability === null) {
            throw new CommandFailedException(\sprintf('"%s" is not a known capability or an installed Trunk package. Run `trunk package:list`.', $target));
        }

        if ($input->flag('purge') && $capability->isBuiltIn()) {
            throw new CommandFailedException(\sprintf('"%s" ships with trunkphp/framework, so there is no package to purge. Run without --purge to disable it.', $capability->id));
        }

        foreach ($this->capabilities->disable($this->project, $capability->id) as $change) {
            $output->success($change);
        }

        if ($input->flag('purge')) {
            $output->info('Running: composer remove ' . $capability->package);
            $code = $this->processes->composerRemove($this->project, $capability->package);

            if ($code !== 0) {
                throw new CommandFailedException(\sprintf('The capability was disabled, but `composer remove` failed (exit code %d).', $code));
            }
        }

        return 0;
    }
}
