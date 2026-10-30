<?php

declare(strict_types=1);

namespace Trunk\Auth\Jwt;

/**
 * The verified claims of a JWT: who it is about (`sub`), who issued it, who it is for, when it ends,
 * and what it allows (the `scope`, `scp` and `permissions` claims, combined). `get()` reads any other claim.
 *
 * @api
 */
final readonly class Claims
{
    /**
     * @param list<string>         $audience
     * @param list<string>         $scopes
     * @param array<string, mixed> $all      every claim, as decoded from JSON
     */
    public function __construct(
        public string $subject,
        public string $issuer,
        public array $audience,
        public int $expiresAt,
        public array $scopes,
        private array $all,
    ) {}

    public function get(string $name): mixed
    {
        return $this->all[$name] ?? null;
    }

    /**
     * True when the token grants the scope (or permission).
     */
    public function can(string $scope): bool
    {
        return \in_array($scope, $this->scopes, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->all;
    }
}
