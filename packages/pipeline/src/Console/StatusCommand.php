<?php

declare(strict_types=1);

namespace Trunk\Pipeline\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Database\Connection\Connection;
use Trunk\Foundation\Configuration;

/**
 * `trunk pipeline:status` lists every run; `trunk pipeline:status <id>` shows just one.
 */
final readonly class StatusCommand implements Command
{
    public function __construct(private Connection $connection, private Configuration $configuration) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('pipeline:status', 'Show pipeline runs and their progress', ['run-id' => 'show just this run (optional)']);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $table = $this->configuration->string('pipeline.table');
        $query = $this->connection->table($table)->orderBy('id', 'desc');
        $runId = $input->argument(0);

        if ($runId !== null) {
            $query->where('id', '=', (int) $runId);
        }

        $rows = $query->limit(50)->get();

        if ($rows === []) {
            $output->info('No pipeline runs found.');

            return 0;
        }

        $output->table(['#', 'Pipeline', 'Status', 'Cursor', 'Records', 'Started', 'Completed'], array_map(self::row(...), $rows));

        return 0;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private static function row(array $row): array
    {
        return [
            self::text($row['id'] ?? null),
            self::text($row['pipeline'] ?? null),
            self::text($row['status'] ?? null),
            self::text($row['cursor'] ?? null),
            self::text($row['records_processed'] ?? null),
            gmdate('Y-m-d H:i:s', self::number($row['started_at'] ?? null)),
            \array_key_exists('completed_at', $row) && $row['completed_at'] !== null ? gmdate('Y-m-d H:i:s', self::number($row['completed_at'])) : '-',
        ];
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
