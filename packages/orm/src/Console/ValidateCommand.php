<?php

declare(strict_types=1);

namespace Trunk\Orm\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Foundation\Configuration;
use Trunk\Orm\Exception\MappingException;
use Trunk\Orm\Mapping\MetadataFactory;

/**
 * `trunk orm:validate`: checks every entity map without building anything.
 */
final readonly class ValidateCommand implements Command
{
    public function __construct(private Configuration $configuration, private MetadataFactory $factory = new MetadataFactory()) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('orm:validate', 'Check every entity map (entities, columns, relations) without building');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $maps = array_values(array_filter($this->configuration->array('orm.maps'), is_string(...)));

        try {
            $metadata = $this->factory->fromClasses($maps);
        } catch (MappingException $e) {
            foreach ($e->errors as $error) {
                $output->failure($error);
            }

            return 1;
        }

        $output->success(\count($metadata) . ' entity map(s) are valid.');

        return 0;
    }
}
