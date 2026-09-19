<?php

declare(strict_types=1);

namespace Trunk\Database\Migration;

use Closure;
use Throwable;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Exception\MigrationException;
use Trunk\Database\Schema\Schema;

/**
 * Runs and rolls back migration files. Each migration runs in a transaction where the database can
 * roll back DDL (SQLite, PostgreSQL); MySQL cannot, so a failed MySQL migration may be half applied.
 */
final readonly class Migrator
{
    public function __construct(
        private Connection $connection,
        private Schema $schema,
        private MigrationRepository $repository,
        private string $path,
    ) {}

    public function directory(): string
    {
        return $this->path;
    }

    /**
     * @return list<MigrationFile> every migration file, oldest first
     */
    public function files(): array
    {
        $files = is_dir($this->path) ? (glob($this->path . '/*.php') ?: []) : [];
        sort($files);

        return array_map(MigrationFile::fromPath(...), $files);
    }

    /**
     * Runs every pending migration and returns their names.
     *
     * @return list<string>
     */
    public function migrate(): array
    {
        $this->repository->ensureTable();
        $ran = $this->repository->ran();
        $batch = $this->repository->nextBatch();
        $done = [];

        foreach ($this->files() as $file) {
            if (isset($ran[$file->name])) {
                continue;
            }

            $this->execute($file, 'up', function () use ($file, $batch): void {
                $this->repository->log($file->name, $batch);
            });
            $done[] = $file->name;
        }

        return $done;
    }

    /**
     * Rolls back the last batch, or the last `$steps` migrations when given. Returns their names.
     *
     * @return list<string>
     */
    public function rollback(int $steps = 0): array
    {
        $this->repository->ensureTable();
        $ran = array_reverse($this->repository->ran(), true);
        $files = [];

        foreach ($this->files() as $file) {
            $files[$file->name] = $file;
        }

        if ($ran === []) {
            return [];
        }

        $selected = $steps > 0 ? \array_slice($ran, 0, $steps, true) : array_filter($ran, static fn(int $batch): bool => $batch === max($ran));
        $rolled = [];

        foreach (array_keys($selected) as $name) {
            $file = $files[$name] ?? throw new MigrationException(\sprintf('Cannot roll back %s: its file is missing from the migrations directory.', $name));
            $this->execute($file, 'down', function () use ($name): void {
                $this->repository->remove($name);
            });
            $rolled[] = $name;
        }

        return $rolled;
    }

    /**
     * @return list<array{name: string, ran: bool, batch: int|null}>
     */
    public function status(): array
    {
        $this->repository->ensureTable();
        $ran = $this->repository->ran();

        return array_map(static fn(MigrationFile $f): array => ['name' => $f->name, 'ran' => isset($ran[$f->name]), 'batch' => $ran[$f->name] ?? null], $this->files());
    }

    /**
     * Drops every table, then runs all migrations again. Destructive.
     *
     * @return list<string>
     */
    public function fresh(): array
    {
        $this->schema->dropAllTables();

        return $this->migrate();
    }

    /**
     * @param Closure(): void $record what to store once the migration has succeeded
     */
    private function execute(MigrationFile $file, string $direction, Closure $record): void
    {
        $migration = $file->load();
        $work = function () use ($migration, $direction, $record): void {
            ($direction === 'up' ? $migration->up : $migration->down)($this->schema);
            $record();
        };

        try {
            $this->connection->driver()->supportsTransactionalDdl() ? $this->connection->transaction($work) : $work();
        } catch (Throwable $e) {
            throw new MigrationException(\sprintf('Migration %s failed while running %s(): %s', $file->name, $direction, $e->getMessage()), 0, $e);
        }
    }
}
