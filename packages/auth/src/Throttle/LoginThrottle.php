<?php

declare(strict_types=1);

namespace Trunk\Auth\Throttle;

use Trunk\Auth\Settings\ThrottleSettings;
use Trunk\Http\Exception\HttpException;

/**
 * Failed-login limits. Two counters guard every attempt: one per identifier and address (guessing one
 * account) and one per address (guessing many).
 */
final readonly class LoginThrottle
{
    public function __construct(private AttemptCounter $counter, private ThrottleSettings $settings) {}

    /**
     * @throws HttpException 429 with Retry-After when either counter is over its limit
     */
    public function assertAllowed(string $identifier, ?string $address): void
    {
        $this->counter->assertBelow([[$this->identifierKey($identifier, $address), $this->settings->maxAttempts], [$this->addressKey($address), $this->settings->maxAttemptsPerIp]]);
    }

    public function recordFailure(string $identifier, ?string $address): void
    {
        $this->counter->hit($this->identifierKey($identifier, $address));
        $this->counter->hit($this->addressKey($address));
    }

    /**
     * A successful login clears the account's counter (the address counter keeps counting).
     */
    public function clear(string $identifier, ?string $address): void
    {
        $this->counter->clear($this->identifierKey($identifier, $address));
    }

    public function prune(): int
    {
        return $this->counter->prune();
    }

    private function identifierKey(string $identifier, ?string $address): string
    {
        return hash('sha256', 'id|' . mb_strtolower(trim($identifier)) . '|' . ($address ?? 'unknown'));
    }

    private function addressKey(?string $address): string
    {
        return hash('sha256', 'ip|' . ($address ?? 'unknown'));
    }
}
