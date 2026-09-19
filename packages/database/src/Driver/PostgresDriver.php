<?php

declare(strict_types=1);

namespace Trunk\Database\Driver;

use PDO;
use PDOException;
use Trunk\Database\Query\Grammar;
use Trunk\Database\Query\PostgresGrammar;
use Trunk\Database\Schema\PostgresSchemaGrammar;
use Trunk\Database\Schema\SchemaGrammar;

final readonly class PostgresDriver implements Driver
{
    public function __construct(private ConfigReader $reader = new ConfigReader()) {}

    public function name(): string
    {
        return 'pgsql';
    }

    public function dsn(array $config): string
    {
        $host = $this->reader->string($config, 'host', '/^[A-Za-z0-9._-]{1,253}$/D', 'use a host name or IP address (no ports or options). Set DB_HOST in .env.', '127.0.0.1');
        $database = $this->reader->string($config, 'database', '/^[A-Za-z0-9_$-]{1,63}$/D', 'use the database name (letters, digits, "_", "$", "-"). Set DB_DATABASE in .env.');
        $sslmode = $this->reader->string($config, 'sslmode', '/^(?:disable|allow|prefer|require|verify-ca|verify-full)$/D', 'use disable, allow, prefer, require, verify-ca or verify-full.', 'prefer');

        return \sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $host, $this->reader->port($config, 5432), $database, $sslmode);
    }

    public function options(array $config): array
    {
        return [PDO::ATTR_TIMEOUT => 5];
    }

    public function username(array $config): ?string
    {
        return $this->reader->optionalString($config, 'username');
    }

    public function password(array $config): ?string
    {
        return $this->reader->optionalString($config, 'password');
    }

    public function afterConnect(PDO $pdo, array $config): void
    {
        $pdo->exec("SET client_encoding TO 'UTF8'");
    }

    public function grammar(): Grammar
    {
        return new PostgresGrammar();
    }

    public function schemaGrammar(): SchemaGrammar
    {
        return new PostgresSchemaGrammar();
    }

    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    public function isConnectionLost(PDOException $e): bool
    {
        return str_starts_with((string) $e->getCode(), '08') || str_contains($e->getMessage(), 'server closed the connection');
    }
}
