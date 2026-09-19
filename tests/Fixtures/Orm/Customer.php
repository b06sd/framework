<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

use DateTimeImmutable;

final class Customer
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        public private(set) ?int $id = null,
        public string $name = '',
        public string $email = '',
        public string $passwordHash = '',
        public Status $status = Status::Active,
        public ?string $nickname = null,
        public bool $active = true,
        public float $balance = 0.0,
        public array $settings = [],
        public DateTimeImmutable $createdAt = new DateTimeImmutable('2026-01-01 00:00:00 UTC'),
        public ?DateTimeImmutable $deletedAt = null,
        public int $version = 1,
    ) {}
}
