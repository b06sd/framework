<?php

declare(strict_types=1);

namespace Trunk\Database\Driver;

use PDO;
use PDOException;
use Trunk\Database\Query\Grammar;
use Trunk\Database\Query\MySqlGrammar;
use Trunk\Database\Schema\MySqlSchemaGrammar;
use Trunk\Database\Schema\SchemaGrammar;

final readonly class MySqlDriver implements Driver
{
    public function __construct(private ConfigReader $reader = new ConfigReader()) {}

    public function name(): string
    {
        return 'mysql';
    }

    public function dsn(array $config): string
    {
        $host = $this->reader->string($config, 'host', '/^[A-Za-z0-9._-]{1,253}$/D', 'use a host name or IP address (no ports or options). Set DB_HOST in .env.', '127.0.0.1');
        $database = $this->reader->string($config, 'database', '/^[A-Za-z0-9_$-]{1,64}$/D', 'use the database name (letters, digits, "_", "$", "-"). Set DB_DATABASE in .env.');
        $charset = $this->reader->string($config, 'charset', '/^[a-z0-9]{2,20}$/D', 'use a charset such as utf8mb4.', 'utf8mb4');

        return \sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $this->reader->port($config, 3306), $database, $charset);
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
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }

    public function grammar(): Grammar
    {
        return new MySqlGrammar();
    }

    public function schemaGrammar(): SchemaGrammar
    {
        return new MySqlSchemaGrammar();
    }

    public function supportsTransactionalDdl(): bool
    {
        return false;
    }

    public function isConnectionLost(PDOException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;

        return \in_array($code, [2006, 2013, 2055], true) || str_contains($e->getMessage(), 'server has gone away');
    }
}
