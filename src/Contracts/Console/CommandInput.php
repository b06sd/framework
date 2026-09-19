<?php

declare(strict_types=1);

namespace Trunk\Contracts\Console;

/**
 * What a command may read from the command line. The console package provides the implementation;
 * commands depend only on this interface, so any package can ship commands without depending on it.
 *
 * @api
 */
interface CommandInput
{
    public function argument(int $index, ?string $default = null): ?string;

    public function option(string $name, ?string $default = null): ?string;

    public function flag(string $name): bool;

    /** True when the user asked for more detail (-v / --verbose). */
    public function verbose(): bool;

    /**
     * Every positional argument, in order.
     *
     * @return list<string>
     */
    public function arguments(): array;
}
