<?php

declare(strict_types=1);

namespace Trunk\Auth\Throttle;

use Trunk\Auth\Settings\ThrottleSettings;
use Trunk\Http\Exception\HttpException;

/**
 * Caps how many sessions one address may start without signing in (a page that shows a CSRF token
 * starts a session for an anonymous visitor). Sessions created by a login, and sessions that already
 * exist, never count. Over the cap the request is refused before anything is stored.
 */
final readonly class SessionCreationLimit
{
    public function __construct(private AttemptCounter $counter, private ThrottleSettings $settings) {}

    /**
     * @throws HttpException 429 with Retry-After
     */
    public function claim(?string $address): void
    {
        $key = hash('sha256', 'session|' . ($address ?? 'unknown'));
        $this->counter->assertBelow([[$key, $this->settings->maxNewSessionsPerIp]]);
        $this->counter->hit($key);
    }
}
