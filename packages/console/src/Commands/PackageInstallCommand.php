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
use Trunk\Foundation\Capability\CapabilityResolver;
use Trunk\Foundation\Capability\ComposerRequirements;
use Trunk\Foundation\Project\Project;

/**
 * Enables a capability. Built-in capabilities need no download; a `vendor/name` package is first
 * fetched with Composer (which runs that package's own install scripts: only install what you trust).
 */
final readonly class PackageInstallCommand implements Command
{
    public function __construct(
        private Project $project,
        private CapabilityManager $capabilities,
        private ProcessRunner $processes,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'package:install',
            'Enable a capability, or install a Composer package that provides one',
            ['capability' => 'a capability id (cache, tusk...) or a Composer package (vendor/name[:constraint])'],
            requiredArguments: 1,
        );
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $target = (string) $input->argument(0);

        if ($this->processes->isPackageSpec($target)) {
            return $this->installPackage($target, $output);
        }

        return $this->enable($target, $output);
    }

    private function installPackage(string $spec, CommandOutput $output): int
    {
        $name = $this->processes->packageName($spec);

        if ($this->capabilities->catalog($this->project)->forPackage($name) === null) {
            $output->info('Running: composer require ' . $spec);
            $code = $this->processes->composerRequire($this->project, $spec);

            if ($code !== 0) {
                throw new CommandFailedException(\sprintf('Composer failed (exit code %d), so nothing was changed in trunk.php.', $code));
            }
        }

        $capability = $this->capabilities->catalog($this->project)->forPackage($name);

        if ($capability === null) {
            $output->warning(\sprintf('%s is installed, but it does not declare a Trunk capability (extra.trunk.capability in its composer.json).', $name));
            $output->line('If it provides a module, add it to the modules list in trunk.php yourself.');

            return 0;
        }

        return $this->enable($capability->id, $output);
    }

    private function enable(string $id, CommandOutput $output): int
    {
        $this->installDependencies($id, $output);

        foreach ($this->capabilities->enable($this->project, $id) as $change) {
            $output->success($change);
        }

        $output->line('Run `trunk doctor` to check the result.');

        return 0;
    }

    /**
     * Installs the Composer packages the capability (and what it requires) needs before trunk.php is
     * touched, so a failed download leaves the project as it was.
     */
    private function installDependencies(string $id, CommandOutput $output): void
    {
        $catalog = $this->capabilities->catalog($this->project);

        if ($catalog->find($id) === null) {
            return;
        }

        $requirements = new ComposerRequirements();
        $missing = $requirements->missing($this->project->basePath, $requirements->for(new CapabilityResolver($catalog)->plan($id, $this->project->modules)));
        $specs = $requirements->specs($missing);
        $extensions = array_diff_key($missing, array_flip(array_map(static fn(string $s): string => explode(':', $s, 2)[0], $specs)));

        if ($extensions !== []) {
            throw new CommandFailedException('Cannot enable ' . $id . ': ' . $requirements->describe($extensions) . '. Install and enable them in PHP, then run this again.');
        }

        if ($specs === []) {
            return;
        }

        $output->info('Running: composer require ' . implode(' ', $specs));

        if ($this->processes->composerRequireAll($this->project, $specs) !== 0) {
            throw new CommandFailedException('Composer could not install what ' . $id . ' needs, so nothing was changed in trunk.php.');
        }
    }
}
