<?php

declare(strict_types=1);

namespace Trunk\Auth\User;

use Trunk\Auth\Settings\UserSettings;
use Trunk\Database\Connection\Connection;

/**
 * Reads users from the configured table through the query builder: every value is a bound parameter
 * and the table and column names come from validated config, never from a request.
 */
final readonly class DatabaseUserProvider implements UserProvider
{
    public function __construct(private Connection $connection, private UserSettings $settings) {}

    public function byId(string $id): ?Authenticatable
    {
        if (!self::isPlain($id)) {
            return null;
        }

        return $this->hydrate($this->connection->table($this->settings->table)->where($this->settings->id, '=', $id)->first());
    }

    public function byIdentifier(string $identifier): ?Authenticatable
    {
        if (!self::isPlain($identifier)) {
            return null;
        }

        return $this->hydrate($this->connection->table($this->settings->table)->where($this->settings->identifier, '=', $identifier)->first());
    }

    public function updatePasswordHash(Authenticatable $user, string $hash): void
    {
        $this->connection->table($this->settings->table)->where($this->settings->id, '=', $user->authId())->update([$this->settings->password => $hash]);
    }

    /**
     * Control characters never belong in an identifier, and a NUL would be cut off by some database
     * drivers, so "ada@example.com" and "ada@example.com\0" would find the same account (and get
     * separate throttle counters). Refuse them, and absurd lengths, before any query runs.
     */
    private static function isPlain(string $value): bool
    {
        return $value !== '' && \strlen($value) <= 255 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function hydrate(?array $row): ?DatabaseUser
    {
        if ($row === null || !isset($row[$this->settings->id])) {
            return null;
        }

        $attributes = [];

        foreach ($row as $column => $value) {
            if ($column !== $this->settings->password && $column !== $this->settings->sessionVersion && (\is_scalar($value) || $value === null)) {
                $attributes[$column] = $value;
            }
        }

        $hash = $row[$this->settings->password] ?? '';
        $version = $row[$this->settings->sessionVersion] ?? '';

        return new DatabaseUser(
            \is_scalar($row[$this->settings->id]) ? (string) $row[$this->settings->id] : '',
            \is_string($hash) ? $hash : '',
            \is_scalar($version) ? (string) $version : '',
            $attributes,
        );
    }
}
