<?php

declare(strict_types=1);

namespace Trunk\Contracts\Console;

use InvalidArgumentException;
use Trunk\Support\ClassName;

/**
 * @api
 */
final class CommandCollector
{
    /** @var list<class-string<Command>> */
    private array $classes = [];

    /**
     * @param class-string<Command> $class also the container id
     */
    public function add(string $class): void
    {
        if (!ClassName::isValid($class) || !is_subclass_of($class, Command::class)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a console command class.', $class));
        }

        $this->classes[] = $class;
    }

    /**
     * @return list<class-string<Command>>
     */
    public function classes(): array
    {
        return $this->classes;
    }
}
