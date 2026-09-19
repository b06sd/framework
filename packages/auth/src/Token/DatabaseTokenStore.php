<?php

declare(strict_types=1);

namespace Trunk\Auth\Token;

use JsonException;
use Trunk\Auth\Settings\TokenSettings;
use Trunk\Database\Connection\Connection;

/**
 * Tokens in the table created by `trunk auth:table`. All values are bound parameters; abilities are
 * stored as JSON.
 */
final readonly class DatabaseTokenStore implements TokenStore
{
    public function __construct(private Connection $connection, private TokenSettings $settings) {}

    public function create(TokenRecord $record): void
    {
        $t = $record->token;
        $this->query()->insert([
            'id' => $t->id,
            'token_hash' => $record->secretHash,
            'user_id' => $t->userId,
            'user_version' => $t->userVersion,
            'name' => $t->name,
            'abilities' => json_encode($t->abilities, \JSON_THROW_ON_ERROR),
            'expires_at' => $t->expiresAt,
            'revoked_at' => $t->revokedAt,
            'last_used_at' => $t->lastUsedAt,
            'created_at' => $t->createdAt,
        ]);
    }

    public function find(string $id): ?TokenRecord
    {
        $row = $this->query()->where('id', '=', $id)->first();

        return $row === null ? null : $this->record($row);
    }

    public function touch(string $id, int $now): void
    {
        $this->query()->where('id', '=', $id)->update(['last_used_at' => $now]);
    }

    public function revoke(string $id, int $now): void
    {
        $this->query()->where('id', '=', $id)->whereNull('revoked_at')->update(['revoked_at' => $now]);
    }

    public function revokeAllFor(string $userId, int $now): int
    {
        return $this->query()->where('user_id', '=', $userId)->whereNull('revoked_at')->update(['revoked_at' => $now]);
    }

    public function forUser(string $userId): array
    {
        $tokens = [];

        foreach ($this->query()->where('user_id', '=', $userId)->orderBy('created_at')->get() as $row) {
            $tokens[] = $this->record($row)?->token;
        }

        return array_values(array_filter($tokens));
    }

    public function prune(int $before): int
    {
        $expired = $this->query()->whereNotNull('expires_at')->where('expires_at', '<', $before)->delete();

        return $expired + $this->query()->whereNotNull('revoked_at')->where('revoked_at', '<', $before)->delete();
    }

    private function query(): \Trunk\Database\Query\QueryBuilder
    {
        return $this->connection->table($this->settings->table);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function record(array $row): ?TokenRecord
    {
        $int = static fn(mixed $v): ?int => is_numeric($v) ? (int) $v : null;

        try {
            $abilities = json_decode(\is_string($row['abilities'] ?? null) ? $row['abilities'] : '[]', true, 8, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($abilities) || !\is_string($row['id'] ?? null) || !\is_string($row['token_hash'] ?? null) || !\is_scalar($row['user_id'] ?? null) || !\is_string($row['name'] ?? null)) {
            return null;
        }

        return new TokenRecord(
            new AccessToken($row['id'], (string) $row['user_id'], \is_scalar($row['user_version'] ?? null) ? (string) $row['user_version'] : '', $row['name'], array_values(array_filter($abilities, is_string(...))), $int($row['expires_at'] ?? null), $int($row['revoked_at'] ?? null), $int($row['last_used_at'] ?? null), $int($row['created_at'] ?? null) ?? 0),
            $row['token_hash'],
        );
    }
}
