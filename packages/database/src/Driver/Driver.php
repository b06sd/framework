<?php

declare(strict_types=1);

namespace Trunk\Database\Driver;

use PDO;
use PDOException;
use Trunk\Database\Query\Grammar;
use Trunk\Database\Schema\SchemaGrammar;

/**
 * Everything that differs between database systems: how to connect, how to write SQL, and how to
 * recognise a dropped connection. Configuration is validated here so a value can never smuggle
 * extra options into the DSN.
 */
interface Driver
{
    public function name(): string;

    /**
     * @param array<string, mixed> $config
     */
    public function dsn(array $config): string;

    /**
     * @param array<string, mixed> $config
     *
     * @return array<int, mixed> PDO connection options
     */
    public function options(array $config): array;

    /**
     * @param array<string, mixed> $config
     */
    public function username(array $config): ?string;

    /**
     * @param array<string, mixed> $config
     */
    public function password(array $config): ?string;

    /**
     * Session settings applied right after connecting.
     *
     * @param array<string, mixed> $config
     */
    public function afterConnect(PDO $pdo, array $config): void;

    public function grammar(): Grammar;

    public function schemaGrammar(): SchemaGrammar;

    public function supportsTransactionalDdl(): bool;

    public function isConnectionLost(PDOException $e): bool;
}
