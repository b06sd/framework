<?php

declare(strict_types=1);

namespace Trunk\Orm\Console;

use Closure;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Runtime;
use Trunk\Orm\UnitOfWork\EntityManager;

/**
 * `trunk db:seed` runs database/seeders/DatabaseSeeder.php, a file the application owns:
 * `return static function (EntityManager $manager): void { ... };`. Disabled in production
 * unless --force is given, since a seeder writes rows directly.
 */
final readonly class SeedCommand implements Command
{
    public function __construct(private EntityManager $manager, private Runtime $runtime) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('db:seed', 'Run database/seeders/DatabaseSeeder.php');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        if ($this->runtime->environment === Environment::Production && !$input->flag('force')) {
            throw new CommandFailedException('db:seed writes rows directly and is disabled in production unless you pass --force.');
        }

        $path = $this->runtime->basePath . '/database/seeders/DatabaseSeeder.php';

        if (!is_file($path)) {
            throw new CommandFailedException('database/seeders/DatabaseSeeder.php does not exist. Create it: `return static function (EntityManager $manager): void { ... };`.');
        }

        $seed = (static fn(string $file): mixed => require $file)($path);

        if (!$seed instanceof Closure) {
            throw new CommandFailedException('database/seeders/DatabaseSeeder.php must return a function: `return static function (EntityManager $manager): void { ... };`.');
        }

        $seed($this->manager);

        $output->success('Seeded the database.');

        return 0;
    }
}
