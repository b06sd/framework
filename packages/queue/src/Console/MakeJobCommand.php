<?php

declare(strict_types=1);

namespace Trunk\Queue\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Runtime;
use Trunk\Support\FileWriter;

/**
 * `trunk make:job SendWelcomeEmail` writes app/Jobs/SendWelcomeEmail.php. It never overwrites.
 */
final readonly class MakeJobCommand implements Command
{
    public function __construct(private Runtime $runtime, private FileWriter $files = new FileWriter()) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('make:job', 'Create a queued job', ['Name' => 'the job class name in PascalCase, e.g. SendWelcomeEmail'], requiredArguments: 1);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $name = (string) $input->argument(0);

        if (preg_match('/^[A-Z][A-Za-z0-9]{0,60}$/D', $name) !== 1) {
            throw new CommandFailedException('The job name must be PascalCase letters and digits, e.g. SendWelcomeEmail.');
        }

        $directory = $this->runtime->basePath . '/app/Jobs';
        $path = $directory . '/' . $name . '.php';

        if (file_exists($path)) {
            throw new CommandFailedException($name . ' already exists in app/Jobs; nothing was overwritten.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new CommandFailedException('app/Jobs could not be created.');
        }

        $this->files->write($path, <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Jobs;

            use Trunk\Queue\Job\Job;
            use Trunk\Queue\Job\JobOptions;

            /**
             * The constructor is the payload: ints, floats, strings, bools, arrays, backed enums and
             * DateTimeImmutable. Pass ids, not entities. handle() receives services from the container.
             */
            final readonly class {$name} implements Job
            {
                public function __construct(public int \$id) {}

                public function handle(): void
                {
                    // Add the services this job needs as parameters, e.g. handle(Mailer \$mailer).
                }

                public static function options(): JobOptions
                {
                    return new JobOptions(tries: 3, backoff: [10, 60, 300], timeout: 60, queue: 'default');
                }
            }

            PHP);

        $output->success('Created app/Jobs/' . $name . '.php');
        $output->line('  Dispatch it with $queue->dispatch(new ' . $name . '(1)), then run "trunk queue:work".');

        return 0;
    }
}
