<?php

declare(strict_types=1);

namespace Trunk\Console\Commands;

use Trunk\Console\Scaffold\Profile;
use Trunk\Console\Scaffold\ProjectScaffolder;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;

final readonly class NewCommand implements Command
{
    public function __construct(
        private ProjectScaffolder $scaffolder,
        private string $workingDirectory,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'new',
            'Create a new Trunk application',
            ['name' => 'the project (and directory) name, e.g. customer-api'],
            [
                '--type=<type>' => 'api, web (default), self-contained, cli or worker',
                '--repository=<path>' => 'use a local checkout of trunk/framework (composer path repository)',
                '--force' => 'write into a non-empty directory',
            ],
            1,
        );
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $name = (string) $input->argument(0);
        $profile = Profile::fromName($input->option('type', 'web') ?? 'web');
        $target = $this->workingDirectory . '/' . $name;
        $created = $this->scaffolder->create($name, $profile, $target, $input->option('repository'), $input->flag('force'));

        $output->success(\sprintf('Created %s (%s): %d files.', $name, $profile->value, \count($created)));
        $output->line($profile->description());
        $output->line();
        $output->line('Next steps:');
        $output->line('  cd ' . $name);
        $output->line('  composer install');
        $output->line($profile->hasHttp() ? '  trunk serve   # http://127.0.0.1:8006' : '  trunk list');
        $output->line();
        $output->line('Settings live in .env (already created; it is git-ignored).');

        return 0;
    }
}
