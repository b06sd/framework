<?php

declare(strict_types=1);

namespace Trunk\Auth\Settings;

final readonly class PasswordSettings
{
    public function __construct(
        public string $algorithm = 'argon2id',
        public int $memoryCost = 65536,
        public int $timeCost = 4,
        public int $threads = 1,
        public int $minLength = 12,
        public int $maxLength = 1024,
    ) {}
}
