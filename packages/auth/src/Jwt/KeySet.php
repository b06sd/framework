<?php

declare(strict_types=1);

namespace Trunk\Auth\Jwt;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use JsonException;
use RuntimeException;
use Throwable;
use Trunk\Contracts\Clock;
use Trunk\Support\FileWriter;

/**
 * The provider's public keys, by key id. The key set is kept in a file for `jwt.cache_ttl` seconds,
 * so a request almost never waits for the provider. A token signed with a key id the set does not
 * have (the provider rotated its keys) refetches it, but at most once a minute, so tokens with made-up
 * key ids cannot make every request call out. Only keys for the configured algorithms are kept.
 *
 * @internal
 */
final class KeySet
{
    private const int REFETCH_AFTER = 60;

    /** @var array{fetchedAt: int, keys: array<string, Key>}|null */
    private ?array $loaded = null;

    public function __construct(
        private readonly JwtSettings $settings,
        private readonly KeySource $source,
        private readonly Clock $clock,
    ) {}

    /**
     * The key for a key id, or null when the provider has none by that id.
     *
     * @throws RuntimeException when the key set cannot be fetched and none is cached
     */
    public function key(string $kid): ?Key
    {
        $now = $this->clock->now();
        $this->loaded ??= $this->read();

        if ($this->loaded === null || $now - $this->loaded['fetchedAt'] >= $this->settings->cacheTtl) {
            $this->loaded = $this->refresh($this->loaded);
        } elseif (!isset($this->loaded['keys'][$kid]) && $now - $this->loaded['fetchedAt'] >= self::REFETCH_AFTER) {
            $this->loaded = $this->refresh($this->loaded);
        }

        return $this->loaded['keys'][$kid] ?? null;
    }

    /**
     * @param array{fetchedAt: int, keys: array<string, Key>}|null $stale
     *
     * @return array{fetchedAt: int, keys: array<string, Key>}
     */
    private function refresh(?array $stale): array
    {
        try {
            $json = $this->source->fetch($this->settings->jwksUrl);
            $keys = $this->parse($json);
        } catch (Throwable $e) {
            if ($stale !== null) {
                return $stale;   // keep verifying with the keys we have rather than refusing everyone
            }

            throw new RuntimeException(\sprintf('JWT signing keys are unavailable: %s', $e->getMessage()), previous: $e);
        }

        $fetched = ['fetchedAt' => $this->clock->now(), 'keys' => $keys];
        $this->write($json, $fetched['fetchedAt']);

        return $fetched;
    }

    /**
     * @return array<string, Key>
     *
     * @throws RuntimeException
     */
    private function parse(string $json): array
    {
        try {
            $jwks = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('the key set is not JSON.', previous: $e);
        }

        if (!\is_array($jwks) || !\is_array($jwks['keys'] ?? null)) {
            throw new RuntimeException('the key set has no "keys".');
        }

        $allowed = array_values(array_intersect($this->settings->algorithms, JwtSettings::ASYMMETRIC));
        $usable = [];

        foreach ($jwks['keys'] as $index => $jwk) {
            if (!\is_array($jwk) || !\is_string($jwk['kid'] ?? null) || (isset($jwk['use']) && $jwk['use'] !== 'sig')) {
                continue;
            }

            try {
                // A key without "alg" is taken to be for the one configured algorithm, when there is one.
                $key = JWK::parseKey($jwk, \count($allowed) === 1 ? $allowed[0] : null);
            } catch (Throwable) {
                continue;   // a key type or curve this server cannot use; others may still be fine
            }

            if ($key !== null && \in_array($key->getAlgorithm(), $allowed, true)) {
                $usable[$jwk['kid']] = $key;
            }
        }

        return $usable !== [] ? $usable : throw new RuntimeException(\sprintf('the key set has no key for %s.', implode(', ', $allowed)));
    }

    /**
     * @return array{fetchedAt: int, keys: array<string, Key>}|null
     */
    private function read(): ?array
    {
        $path = $this->path();
        $cached = $path !== null && is_file($path) ? @file_get_contents($path) : false;

        if (!\is_string($cached)) {
            return null;
        }

        try {
            $data = json_decode($cached, true, 16, \JSON_THROW_ON_ERROR);

            return \is_array($data) && \is_int($data['fetchedAt'] ?? null) && \is_string($data['jwks'] ?? null)
                ? ['fetchedAt' => $data['fetchedAt'], 'keys' => $this->parse($data['jwks'])]
                : null;
        } catch (Throwable) {
            return null;   // a damaged cache is refetched
        }
    }

    private function write(string $json, int $fetchedAt): void
    {
        $path = $this->path();

        if ($path === null) {
            return;
        }

        try {
            if (!is_dir(\dirname($path))) {
                @mkdir(\dirname($path), 0o750, true);
            }

            new FileWriter()->write($path, json_encode(['fetchedAt' => $fetchedAt, 'jwks' => $json], \JSON_THROW_ON_ERROR), 0o640);
        } catch (Throwable) {
            // The cache only saves a request; verification does not depend on it.
        }
    }

    private function path(): ?string
    {
        return $this->settings->cachePath === '' ? null : $this->settings->cachePath . '/jwks-' . substr(hash('sha256', $this->settings->jwksUrl), 0, 16) . '.json';
    }
}
