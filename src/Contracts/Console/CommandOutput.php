<?php

declare(strict_types=1);

namespace Trunk\Contracts\Console;

/**
 * Where a command writes. Messages are plain text; styling is the console package's business.
 *
 * @api
 */
interface CommandOutput
{
    public function write(string $text): void;

    public function line(string $text = ''): void;

    public function title(string $text): void;

    public function info(string $text): void;

    public function success(string $text): void;

    public function warning(string $text): void;

    public function failure(string $text): void;

    public function error(string $text): void;

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void;
}
