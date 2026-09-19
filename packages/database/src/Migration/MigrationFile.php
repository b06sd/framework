<?php

declare(strict_types=1);

namespace Trunk\Database\Migration;

use Trunk\Database\Exception\MigrationException;

/**
 * A migration file on disk. Names look like `2026_09_19_120000_create_users_table.php`; anything
 * else in the directory is rejected so a stray file can never be run by accident.
 */
final readonly class MigrationFile
{
    public const string PATTERN = '/^(\d{4}_\d{2}_\d{2}_\d{6}_[a-z][a-z0-9_]{0,80})\.php$/D';

    public function __construct(
        public string $name,
        public string $path,
    ) {}

    public static function fromPath(string $path): self
    {
        if (preg_match(self::PATTERN, basename($path), $m) !== 1) {
            throw new MigrationException(\sprintf('"%s" is not a valid migration file name. Use yyyy_mm_dd_hhmmss_snake_case_name.php (create one with `trunk make:migration`).', basename($path)));
        }

        return new self($m[1], $path);
    }

    public function load(): Migration
    {
        $migration = (static fn(string $file): mixed => require $file)($this->path);

        return $migration instanceof Migration ? $migration : throw new MigrationException(\sprintf('Migration %s must return a new Migration(up: ..., down: ...).', $this->name));
    }
}
