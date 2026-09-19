<?php

declare(strict_types=1);

namespace Trunk\Database\Driver;

use PDO;
use PDOException;
use Trunk\Database\Exception\ConnectionException;
use Trunk\Database\Query\Grammar;
use Trunk\Database\Query\SqliteGrammar;
use Trunk\Database\Schema\SchemaGrammar;
use Trunk\Database\Schema\SqliteSchemaGrammar;

final readonly class SqliteDriver implements Driver
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function dsn(array $config): string
    {
        $path = $config['database'] ?? null;

        if (!\is_string($path) || $path === '' || str_contains($path, "\0") || preg_match('/[;=?]/', $path) === 1) {
            throw new ConnectionException('Database setting "database" for SQLite must be a file path or ":memory:" (it may not contain ";", "=" or "?"). Set DB_DATABASE in .env.');
        }

        return 'sqlite:' . $path;
    }

    public function options(array $config): array
    {
        return [PDO::ATTR_TIMEOUT => 5];
    }

    public function username(array $config): ?string
    {
        return null;
    }

    public function password(array $config): ?string
    {
        return null;
    }

    public function afterConnect(PDO $pdo, array $config): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');

        if (($config['database'] ?? '') !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }

    public function grammar(): Grammar
    {
        return new SqliteGrammar();
    }

    public function schemaGrammar(): SchemaGrammar
    {
        return new SqliteSchemaGrammar();
    }

    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    public function isConnectionLost(PDOException $e): bool
    {
        return false;
    }
}
