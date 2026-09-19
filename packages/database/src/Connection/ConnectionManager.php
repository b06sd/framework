<?php

declare(strict_types=1);

namespace Trunk\Database\Connection;

use Trunk\Database\Exception\ConnectionException;

/**
 * Holds the named connections from config/database.php and opens each lazily. `connection()` with no
 * name returns the default one.
 *
 * @api
 */
final class ConnectionManager
{
    /** @var array<string, Connection> */
    private array $connections = [];

    private readonly ?QueryLog $log;

    /**
     * @param array<array-key, mixed> $definitions connection name => settings
     *
     * @internal wired by the container, not part of the API
     */
    public function __construct(
        private readonly string $default,
        private readonly array $definitions,
        bool $logQueries = false,
        private readonly ConnectionFactory $factory = new ConnectionFactory(),
    ) {
        $this->log = $logQueries ? new QueryLog() : null;
    }

    /**
     * The connections that have been opened so far (for cleanup between units of work).
     *
     * @return list<Connection>
     */
    public function open(): array
    {
        return array_values($this->connections);
    }

    public function connection(?string $name = null): Connection
    {
        $name ??= $this->default;

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $config = $this->definitions[$name] ?? null;

        if (!\is_array($config)) {
            throw new ConnectionException(\sprintf('There is no database connection named "%s". Check "database.default" (DB_CONNECTION in .env) and "database.connections" in config/database.php.', $name));
        }

        $settings = [];

        foreach ($config as $key => $value) {
            $settings[(string) $key] = $value;
        }

        return $this->connections[$name] = $this->factory->make($name, $settings, $this->log);
    }
    /** @internal wired by the container, not part of the API */

    public function log(): ?QueryLog
    {
        return $this->log;
    }

    public function disconnectAll(): void
    {
        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }
    }
}
