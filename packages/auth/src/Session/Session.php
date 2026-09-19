<?php

declare(strict_types=1);

namespace Trunk\Auth\Session;

use InvalidArgumentException;
use LogicException;

/**
 * The current request's session: a small bag of JSON-safe values that survives between requests.
 * It is created fresh for every request scope, filled by `SessionMiddleware` and saved after the
 * response is built, so add that middleware to any route that uses it. Keys starting with `_trunk.`
 * belong to the framework.
 *
 * @api
 */
final class Session
{
    private const string RESERVED = '_trunk.';

    private const string FLASH = '_trunk.flash';

    private bool $started = false;

    private ?string $id = null;

    private int $createdAt = 0;

    private int $lastActivity = 0;

    /** @var array<string, mixed> */
    private array $data = [];

    private bool $dirty = false;

    private bool $regenerate = false;

    private bool $freshLifetime = false;

    private bool $invalidated = false;

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['started' => $this->started, 'keys' => array_keys($this->data)];
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($this->userKey($key), $this->started()->data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->started()->data[$this->userKey($key)] ?? $default;
    }

    /**
     * @param mixed $value strings, numbers, booleans, null, or arrays of them (nothing else can be stored)
     */
    public function put(string $key, mixed $value): void
    {
        $this->started();
        self::assertStorable($value, 0);
        $this->data[$this->userKey($key)] = $value;
        $this->dirty = true;
    }

    public function forget(string $key): void
    {
        $this->started();
        unset($this->data[$this->userKey($key)]);
        $this->dirty = true;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    /**
     * Everything the application stored (not the framework's own entries).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_filter($this->started()->data, static fn(string $key): bool => !str_starts_with($key, self::RESERVED), \ARRAY_FILTER_USE_KEY);
    }

    /**
     * Keeps a value for the next request only (a "saved" message after a redirect).
     */
    public function flash(string $key, mixed $value): void
    {
        $this->started();
        self::assertStorable($value, 0);
        $flash = $this->flashBag();
        $flash['new'][$this->userKey($key)] = $value;
        $this->data[self::FLASH] = $flash;
        $this->dirty = true;
    }

    /**
     * A value flashed by the previous request.
     */
    public function flashed(string $key, mixed $default = null): mixed
    {
        return $this->flashBag()['old'][$this->userKey($key)] ?? $default;
    }

    /**
     * Gives the session a new id at the end of this request (the old one stops working). Do this
     * whenever privileges change; `Auth::login()` and `logout()` already do.
     */
    public function regenerate(bool $freshLifetime = false): void
    {
        $this->started();
        $this->regenerate = true;
        $this->freshLifetime = $this->freshLifetime || $freshLifetime;
        $this->dirty = true;
    }

    /**
     * Empties the session and ends it: the id stops working and the cookie is removed.
     */
    public function invalidate(): void
    {
        $this->started();
        $this->data = [];
        $this->invalidated = true;
        $this->regenerate = true;
        $this->freshLifetime = true;
        $this->dirty = true;
    }

    /** @internal set by the framework: the value of a `_trunk.` entry */
    public function internal(string $key, mixed $default = null): mixed
    {
        return $this->started()->data[self::RESERVED . $key] ?? $default;
    }

    /** @internal set by the framework: stores a `_trunk.` entry */
    public function setInternal(string $key, mixed $value): void
    {
        $this->started();
        self::assertStorable($value, 0);
        $this->data[self::RESERVED . $key] = $value;
        $this->dirty = true;
    }

    /** @internal set by the framework: removes a `_trunk.` entry */
    public function forgetInternal(string $key): void
    {
        $this->started();
        unset($this->data[self::RESERVED . $key]);
        $this->dirty = true;
    }

    /** @internal called by SessionMiddleware at the start of the request */
    public function start(?string $id, ?SessionRecord $record, int $now): void
    {
        $this->started = true;
        $this->id = $id;
        $this->data = $record->data ?? [];
        $this->createdAt = $record->createdAt ?? $now;
        $this->lastActivity = $record->lastActivity ?? $now;
        $this->dirty = false;
        $this->regenerate = false;
        $this->freshLifetime = false;
        $this->invalidated = false;

        // Flash data moves from "next request" to "this request", once.
        $flash = $this->flashBag();

        if ($flash['new'] !== [] || $flash['old'] !== []) {
            $this->data[self::FLASH] = ['old' => $flash['new'], 'new' => []];
            $this->dirty = true;
        }
    }

    /** @internal */
    public function plainId(): ?string
    {
        return $this->id;
    }

    /** @internal */
    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /** @internal */
    public function shouldRegenerate(): bool
    {
        return $this->regenerate;
    }

    /** @internal */
    public function hasFreshLifetime(): bool
    {
        return $this->freshLifetime;
    }

    /** @internal */
    public function wasInvalidated(): bool
    {
        return $this->invalidated;
    }

    /** @internal */
    public function hasContent(): bool
    {
        return $this->data !== [];
    }

    /** @internal */
    public function createdAt(): int
    {
        return $this->createdAt;
    }

    /** @internal */
    public function lastActivity(): int
    {
        return $this->lastActivity;
    }

    /** @internal what the store should keep */
    public function record(int $createdAt, int $now): SessionRecord
    {
        $data = $this->data;
        $flash = $this->flashBag();

        // What was flashed for this request has now been shown; only "new" survives.
        unset($data[self::FLASH]);

        if ($flash['new'] !== []) {
            $data[self::FLASH] = ['old' => [], 'new' => $flash['new']];
        }

        return new SessionRecord($data, $createdAt, $now);
    }

    private function started(): self
    {
        if (!$this->started) {
            throw new LogicException('The session has not been started. Add Trunk\\Auth\\Http\\SessionMiddleware to the route or group middleware of the routes that use the session.');
        }

        return $this;
    }

    private function userKey(string $key): string
    {
        if ($key === '' || \strlen($key) > 128 || str_starts_with($key, self::RESERVED) || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new InvalidArgumentException('A session key is 1 to 128 characters, has no control characters and does not start with "_trunk.".');
        }

        return $key;
    }

    /**
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    private function flashBag(): array
    {
        $flash = $this->data[self::FLASH] ?? [];
        $old = \is_array($flash) && \is_array($flash['old'] ?? null) ? $flash['old'] : [];
        $new = \is_array($flash) && \is_array($flash['new'] ?? null) ? $flash['new'] : [];

        return ['old' => $this->stringKeys($old), 'new' => $this->stringKeys($new)];
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function stringKeys(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    private static function assertStorable(mixed $value, int $depth): void
    {
        if ($depth > 16) {
            throw new InvalidArgumentException('Session values may be nested at most 16 levels deep.');
        }

        if ($value === null || \is_bool($value) || \is_int($value)) {
            return;
        }

        if (\is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('Session values cannot be NaN or infinite.');
            }

            return;
        }

        if (\is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('Session strings must be valid UTF-8.');
            }

            return;
        }

        if (\is_array($value)) {
            foreach ($value as $item) {
                self::assertStorable($item, $depth + 1);
            }

            return;
        }

        throw new InvalidArgumentException(\sprintf('A session can hold only strings, numbers, booleans, null and arrays of them, not %s. Store an id and load the object again.', get_debug_type($value)));
    }
}
