<?php

declare(strict_types=1);

namespace Trunk\Auth\Session;

/**
 * What a session store keeps for one session: JSON-safe data and two timestamps. The session id is
 * not in it; stores are keyed by the id's SHA-256.
 *
 * @api
 */
final readonly class SessionRecord
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(public array $data, public int $createdAt, public int $lastActivity) {}
}
