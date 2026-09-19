<?php

declare(strict_types=1);

namespace Trunk\Database\Migration;

use Trunk\Database\Connection\Connection;
use Trunk\Database\Schema\Blueprint;
use Trunk\Database\Schema\Schema;

/**
 * Remembers which migrations have run, in the `trunk_migrations` table.
 */
final readonly class MigrationRepository
{
    public const string TABLE = 'trunk_migrations';

    public function __construct(
        private Connection $connection,
        private Schema $schema,
    ) {}

    public function ensureTable(): void
    {
        if (!$this->schema->hasTable(self::TABLE)) {
            $this->schema->create(self::TABLE, static function (Blueprint $table): void {
                $table->id();
                $table->string('migration')->unique();
                $table->integer('batch');
                $table->timestamp('ran_at')->nullable();
            });
        }
    }

    /**
     * @return array<string, int> migration name => batch, in the order they ran
     */
    public function ran(): array
    {
        $ran = [];

        foreach ($this->connection->table(self::TABLE)->orderBy('id')->get() as $row) {
            $name = $row['migration'] ?? null;

            if (\is_string($name)) {
                $ran[$name] = \is_int($row['batch'] ?? null) ? $row['batch'] : 0;
            }
        }

        return $ran;
    }

    public function log(string $migration, int $batch): void
    {
        $this->connection->table(self::TABLE)->insert(['migration' => $migration, 'batch' => $batch, 'ran_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function remove(string $migration): void
    {
        $this->connection->table(self::TABLE)->where('migration', $migration)->delete();
    }

    public function nextBatch(): int
    {
        $max = $this->connection->table(self::TABLE)->max('batch');

        return (\is_int($max) || (\is_string($max) && ctype_digit($max)) ? (int) $max : 0) + 1;
    }
}
