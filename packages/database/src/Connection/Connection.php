<?php

declare(strict_types=1);

namespace Trunk\Database\Connection;

use Closure;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;
use Trunk\Database\Driver\Driver;
use Trunk\Database\Exception\ConnectionException;
use Trunk\Database\Exception\InvalidQueryException;
use Trunk\Database\Exception\QueryException;
use Trunk\Database\Query\Grammar;
use Trunk\Database\Query\QueryBuilder;
use Trunk\Database\Query\Value;

/**
 * A lazily opened PDO connection with safe defaults (exceptions, native prepared statements,
 * associative rows, real types). Values are only ever passed as bindings. Nested transactions use
 * savepoints. Errors never expose bound values, credentials or the DSN.
 *
 * @api
 */
final class Connection
{
    private const int STATEMENT_CACHE = 64;

    private ?PDO $pdo = null;

    private int $depth = 0;

    /** @var array<string, PDOStatement> */
    private array $statements = [];

    private ?Grammar $grammar = null;

    /**
     * @param array<string, mixed> $config
     *
     * @internal wired by the container, not part of the API
     */
    public function __construct(
        private readonly string $name,
        private readonly Driver $driver,
        private readonly array $config,
        private readonly ?QueryLog $log = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'driver' => $this->driver->name(), 'connected' => $this->pdo !== null, 'transactionDepth' => $this->depth];
    }

    public function name(): string
    {
        return $this->name;
    }
    /** @internal wired by the container, not part of the API */

    public function driver(): Driver
    {
        return $this->driver;
    }
    /** @internal wired by the container, not part of the API */

    public function grammar(): Grammar
    {
        // Stateless apart from a bounded memo of wrapped identifiers, so one instance serves every query.
        return $this->grammar ??= $this->driver->grammar();
    }
    /** @internal wired by the container, not part of the API */

    public function log(): ?QueryLog
    {
        return $this->log;
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    /**
     * @param array<array-key, mixed> $bindings positional (list) or named (`['status' => 'x']`)
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings, static function (PDOStatement $statement): array {
            $rows = [];

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (\is_array($row)) {
                    $rows[] = array_combine(array_map(strval(...), array_keys($row)), array_values($row));
                }
            }

            return $rows;
        });
    }

    /**
     * @param array<array-key, mixed> $bindings
     *
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        return $this->select($sql, $bindings)[0] ?? null;
    }

    /**
     * The first column of the first row.
     *
     * @param array<array-key, mixed> $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $row = $this->selectOne($sql, $bindings);

        return $row === null ? null : reset($row);
    }

    /**
     * Runs INSERT/UPDATE/DELETE/DDL and returns the number of affected rows.
     *
     * @param array<array-key, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int
    {
        // Schema changes invalidate prepared statements on some servers, so start fresh after them.
        if (preg_match('/^\s*(?:CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i', $sql) === 1) {
            $this->statements = [];
        }

        return $this->run($sql, $bindings, static fn(PDOStatement $statement): int => $statement->rowCount());
    }

    public function lastInsertId(?string $sequence = null): int|string
    {
        $id = $this->pdo()->lastInsertId($sequence);

        return $id !== false && ctype_digit($id) ? (int) $id : (string) $id;
    }

    /**
     * Runs `$work` in a transaction (a savepoint when already inside one). Any Throwable rolls back
     * and is rethrown.
     *
     * @template T
     *
     * @param Closure(self): T $work
     *
     * @return T
     */
    public function transaction(Closure $work): mixed
    {
        $this->beginTransaction();

        try {
            $result = $work($this);
            $this->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->depth > 0) {
                $this->rollBack();
            }

            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        $pdo = $this->pdo();

        if ($this->depth === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT trunk_sp_' . $this->depth);
        }

        ++$this->depth;
    }

    public function commit(): void
    {
        $this->assertInTransaction('commit');

        if ($this->depth === 1) {
            $this->pdo()->commit();
        } else {
            $this->pdo()->exec('RELEASE SAVEPOINT trunk_sp_' . ($this->depth - 1));
        }

        --$this->depth;
    }

    public function rollBack(): void
    {
        $this->assertInTransaction('roll back');

        if ($this->depth === 1) {
            $this->pdo()->rollBack();
        } else {
            $this->pdo()->exec('ROLLBACK TO SAVEPOINT trunk_sp_' . ($this->depth - 1));
        }

        --$this->depth;
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    public function transactionDepth(): int
    {
        return $this->depth;
    }

    /**
     * Closes the connection (it reopens on the next query). Any open transaction is lost.
     */
    public function disconnect(): void
    {
        $this->pdo = null;
        $this->statements = [];
        $this->depth = 0;
    }

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        try {
            $pdo = new PDO(
                $this->driver->dsn($this->config),
                $this->driver->username($this->config),
                $this->driver->password($this->config),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                    ...$this->driver->options($this->config),
                ],
            );
            $this->driver->afterConnect($pdo, $this->config);
        } catch (PDOException $e) {
            // The driver message can contain the host and user name; only the codes are kept.
            throw new ConnectionException(\sprintf('Could not connect to the "%s" database (%s, SQLSTATE %s). Check the DB_* settings in .env.', $this->name, $this->driver->name(), (string) $e->getCode()));
        }

        return $this->pdo = $pdo;
    }

    /**
     * @template T
     *
     * @param array<array-key, mixed>       $bindings
     * @param Closure(PDOStatement): T $collect
     *
     * @return T
     */
    private function run(string $sql, array $bindings, Closure $collect, bool $retried = false): mixed
    {
        $started = hrtime(true);

        try {
            $statement = $this->statement($sql);
            $this->bind($statement, $bindings);
            $statement->execute();
            $result = $collect($statement);
            $statement->closeCursor();
        } catch (PDOException $e) {
            if (!$retried && $this->depth === 0 && $this->driver->isConnectionLost($e)) {
                $this->disconnect();

                return $this->run($sql, $bindings, $collect, true);
            }

            throw QueryException::fromSqlState($sql, $this->sqlState($e), $e);
        }

        $this->log?->record($sql, \count($bindings), (hrtime(true) - $started) / 1_000_000);

        return $result;
    }

    private function statement(string $sql): PDOStatement
    {
        if (isset($this->statements[$sql])) {
            return $this->statements[$sql];
        }

        if (\count($this->statements) >= self::STATEMENT_CACHE) {
            array_shift($this->statements);
        }

        return $this->statements[$sql] = $this->pdo()->prepare($sql);
    }

    /**
     * @param array<array-key, mixed> $bindings
     */
    private function bind(PDOStatement $statement, array $bindings): void
    {
        foreach ($bindings as $key => $raw) {
            $value = Value::normalize($raw);

            if (\is_string($key)) {
                if (preg_match('/^:?[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1) {
                    throw new InvalidQueryException('Named bindings must be simple names such as "status" or ":status".');
                }

                $parameter = ':' . ltrim($key, ':');
            } else {
                $parameter = $key + 1;
            }

            $statement->bindValue($parameter, $value, match (true) {
                $value === null => PDO::PARAM_NULL,
                \is_bool($value) => PDO::PARAM_BOOL,
                \is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            });
        }
    }

    private function sqlState(PDOException $e): string
    {
        $state = $e->errorInfo[0] ?? $e->getCode();

        return \is_string($state) && preg_match('/^[0-9A-Z]{5}$/D', $state) === 1 ? $state : 'HY000';
    }

    private function assertInTransaction(string $action): void
    {
        if ($this->depth === 0) {
            throw new InvalidQueryException(\sprintf('Cannot %s: there is no open transaction.', $action));
        }
    }
}
