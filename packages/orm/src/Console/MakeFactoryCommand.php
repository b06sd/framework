<?php

declare(strict_types=1);

namespace Trunk\Orm\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Runtime;
use Trunk\Orm\Exception\MappingException;
use Trunk\Orm\Mapping\ColumnMetadata;
use Trunk\Orm\Mapping\MetadataFactory;
use Trunk\Orm\Mapping\Type;
use Trunk\Support\FileWriter;

/**
 * `trunk make:factory Customer` reads Customer's compiled map and writes app/Factories/CustomerFactory.php:
 * one fakerphp/faker call per mapped column, so a test gets a realistic, ready-to-persist entity with
 * `new CustomerFactory()->make()`. The entity must already be mapped (`trunk make:entity` first). It
 * never overwrites.
 */
final readonly class MakeFactoryCommand implements Command
{
    public function __construct(
        private Configuration $configuration,
        private Runtime $runtime,
        private MetadataFactory $factory = new MetadataFactory(),
        private FileWriter $files = new FileWriter(),
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('make:factory', 'Create a factory for a mapped entity', ['Name' => 'the entity class name in PascalCase, e.g. Customer'], requiredArguments: 1);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $name = (string) $input->argument(0);

        if (preg_match('/^[A-Z][A-Za-z0-9]{0,60}$/D', $name) !== 1) {
            throw new CommandFailedException('The entity name must be PascalCase letters and digits, e.g. Customer.');
        }

        $maps = array_values(array_filter($this->configuration->array('orm.maps'), is_string(...)));

        try {
            $metadata = $this->factory->fromClasses($maps);
        } catch (MappingException $e) {
            throw new CommandFailedException(implode(' ', $e->errors));
        }

        $entity = 'App\\Entities\\' . $name;
        $spec = $metadata[$entity] ?? null;

        if ($spec === null) {
            throw new CommandFailedException(\sprintf('%s is not a mapped entity. Run `trunk make:entity %s` first, or check its map is listed under app/Orm.', $entity, $name));
        }

        $path = $this->runtime->basePath . '/app/Factories/' . $name . 'Factory.php';

        if (file_exists($path)) {
            throw new CommandFailedException('app/Factories/' . $name . 'Factory.php already exists; nothing was overwritten.');
        }

        $directory = $this->runtime->basePath . '/app/Factories';

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new CommandFailedException('app/Factories could not be created.');
        }

        $skip = array_filter([$spec->idProperty, $spec->softDeleteProperty, $spec->versionProperty], static fn(?string $p): bool => $p !== null);
        $locals = '';
        $fields = '';

        foreach ($spec->columns as $property => $column) {
            if ($column->hidden || \in_array($property, $skip, true)) {
                continue;
            }

            $locals .= "        \${$property} = \$overrides['{$property}'] ?? null;\n";
            $fields .= "            {$property}: " . self::narrow($property, $column) . ",\n";
        }

        $this->files->write($path, <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Factories;

            use App\\Entities\\{$name};
            use Faker\\Factory as FakerFactory;
            use Faker\\Generator;

            /**
             * make() returns a new, unsaved {$name} with realistic fake data. Pass \$overrides for anything
             * a test needs to control, e.g. \$factory->make(['id' => 1]).
             */
            final readonly class {$name}Factory
            {
                private Generator \$faker;

                public function __construct()
                {
                    \$this->faker = FakerFactory::create();
                }

                /**
                 * @param array<string, mixed> \$overrides
                 */
                public function make(array \$overrides = []): {$name}
                {
            {$locals}
                    return new {$name}(
            {$fields}        );
                }
            }

            PHP);

        $output->success('Created app/Factories/' . $name . 'Factory.php');
        $output->line('  Needs fakerphp/faker: `composer require --dev fakerphp/faker` if it is not installed yet.');
        $output->line('  Use it from database/seeders/DatabaseSeeder.php, then run `trunk db:seed`.');

        return 0;
    }

    /**
     * One constructor argument's value: `$overrides[$property]` if it was given and is the right
     * type, fake data otherwise. Narrowed with is_*()/instanceof against the local variable the
     * generated make() assigns it to first, so the generated file passes PHPStan at the maximum
     * level despite $overrides being array<string, mixed> (a plain `??` would still type as mixed).
     */
    private static function narrow(string $property, ColumnMetadata $column): string
    {
        $var = '$' . $property;
        $enum = $column->enum !== null ? '\\' . ltrim($column->enum, '\\') : '\\UnitEnum';

        $fake = match ($column->type) {
            Type::Int => '$this->faker->numberBetween(1, 1000)',
            Type::Float => '$this->faker->randomFloat(2, 0, 1000)',
            Type::Bool => '$this->faker->boolean()',
            Type::DateTime => '\DateTimeImmutable::createFromMutable($this->faker->dateTime())',
            Type::Json => '[]',
            Type::String => '$this->faker->word()',
            Type::Enum => $enum . '::cases()[array_rand(' . $enum . '::cases())]',
        };

        return match ($column->type) {
            Type::Int => "\\is_int({$var}) ? {$var} : {$fake}",
            Type::Float => "\\is_float({$var}) ? {$var} : {$fake}",
            Type::Bool => "\\is_bool({$var}) ? {$var} : {$fake}",
            Type::String => "\\is_string({$var}) ? {$var} : {$fake}",
            Type::Json => "\\is_array({$var}) ? {$var} : {$fake}",
            Type::DateTime => "{$var} instanceof \\DateTimeImmutable ? {$var} : {$fake}",
            Type::Enum => "{$var} instanceof {$enum} ? {$var} : {$fake}",
        };
    }
}
