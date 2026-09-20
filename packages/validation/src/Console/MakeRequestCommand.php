<?php

declare(strict_types=1);

namespace Trunk\Validation\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Runtime;
use Trunk\Support\FileWriter;

/**
 * `trunk make:request Signup` writes app/Requests/Signup.php. It never overwrites.
 */
final readonly class MakeRequestCommand implements Command
{
    public function __construct(private Runtime $runtime, private FileWriter $files = new FileWriter()) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('make:request', 'Create a validated request class', ['Name' => 'the class name in PascalCase, e.g. Signup'], requiredArguments: 1);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $name = (string) $input->argument(0);

        if (preg_match('/^[A-Z][A-Za-z0-9]{0,60}$/D', $name) !== 1) {
            throw new CommandFailedException('The request name must be PascalCase letters and digits, e.g. Signup.');
        }

        $directory = $this->runtime->basePath . '/app/Requests';
        $path = $directory . '/' . $name . '.php';

        if (file_exists($path)) {
            throw new CommandFailedException($name . ' already exists in app/Requests; nothing was overwritten.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new CommandFailedException('app/Requests could not be created.');
        }

        $this->files->write($path, <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Requests;

            use Trunk\Validation\Rules\Email;
            use Trunk\Validation\Rules\Length;
            use Trunk\Validation\Rules\Required;

            /**
             * Each constructor parameter is a field of the request; its type says what is accepted and the
             * attributes say what else must hold. Nullable or defaulted fields may be left out.
             */
            final readonly class {$name}
            {
                public function __construct(
                    #[Required, Length(max: 100)] public string \$name,
                    #[Required, Email] public string \$email,
                ) {}
            }

            PHP);

        $output->success('Created app/Requests/' . $name . '.php');
        $output->line('  Use it with \$requests->validate(' . $name . '::class, \$request) in a controller.');

        return 0;
    }
}
