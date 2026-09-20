<?php

declare(strict_types=1);

namespace Trunk\Orm\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Runtime;
use Trunk\Orm\Mapping\MapBuilder;
use Trunk\Support\FileWriter;

/**
 * `trunk make:entity Customer` writes app/Entities/Customer.php (what the domain is: a plain class) and
 * app/Orm/CustomerMap.php (how it is stored: the map). It never
 * overwrites, and the name must be a plain PascalCase identifier.
 */
final readonly class MakeEntityCommand implements Command
{
    public function __construct(private Runtime $runtime, private FileWriter $files = new FileWriter()) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('make:entity', 'Create an entity and its map', ['Name' => 'the entity class name in PascalCase, e.g. Customer'], requiredArguments: 1);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $name = (string) $input->argument(0);

        if (preg_match('/^[A-Z][A-Za-z0-9]{0,60}$/D', $name) !== 1) {
            throw new CommandFailedException('The entity name must be PascalCase letters and digits, e.g. Customer.');
        }

        $entity = $this->runtime->basePath . '/app/Entities/' . $name . '.php';
        $map = $this->runtime->basePath . '/app/Orm/' . $name . 'Map.php';

        foreach (['app/Entities/' . $name . '.php' => $entity, 'app/Orm/' . $name . 'Map.php' => $map] as $relative => $path) {
            if (file_exists($path)) {
                throw new CommandFailedException($relative . ' already exists; nothing was overwritten.');
            }
        }

        foreach (['app/Entities', 'app/Orm'] as $relative) {
            $directory = $this->runtime->basePath . '/' . $relative;

            if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
                throw new CommandFailedException($relative . ' could not be created.');
            }
        }

        $table = MapBuilder::snake($name) . 's';
        $this->files->write($entity, <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Entities;

            final class {$name}
            {
                public function __construct(
                    public private(set) ?int \$id = null,
                    public string \$name = '',
                ) {}
            }

            PHP);
        $this->files->write($map, <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Orm;

            use App\\Entities\\{$name};
            use Trunk\\Orm\\Mapping\\EntityMap;
            use Trunk\\Orm\\Mapping\\MapBuilder;

            final class {$name}Map implements EntityMap
            {
                public function entity(): string
                {
                    return {$name}::class;
                }

                public function define(MapBuilder \$map): void
                {
                    \$map->table('{$table}');
                    \$map->id();
                    \$map->string('name')->filterable()->sortable();
                }
            }

            PHP);

        $output->success('Created app/Entities/' . $name . '.php and app/Orm/' . $name . 'Map.php');
        $output->line('  Create the "' . $table . '" table with `trunk make:migration create_' . $table . '_table`, then `trunk orm:validate`.');

        return 0;
    }
}
