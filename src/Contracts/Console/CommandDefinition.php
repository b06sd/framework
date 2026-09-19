<?php

declare(strict_types=1);

namespace Trunk\Contracts\Console;

/**
 * Describes a command for `help` and validates its positional arguments.
 *
 * @api
 */
final readonly class CommandDefinition
{
    /**
     * @param array<string, string> $arguments name => description (all listed arguments are documented; the first `$requiredArguments` are mandatory)
     * @param array<string, string> $options   "--name=value" or "--flag" => description
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $arguments = [],
        public array $options = [],
        public int $requiredArguments = 0,
    ) {}

    public function usage(): string
    {
        $arguments = [];
        $index = 0;

        foreach (array_keys($this->arguments) as $name) {
            $arguments[] = $index++ < $this->requiredArguments ? '<' . $name . '>' : '[<' . $name . '>]';
        }

        return trim('trunk ' . $this->name . ' ' . implode(' ', $arguments));
    }
}
