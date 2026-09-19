<?php

declare(strict_types=1);

namespace Trunk\Auth\Settings;

final readonly class UserSettings
{
    public function __construct(
        public string $table = 'users',
        public string $id = 'id',
        public string $identifier = 'email',
        public string $password = 'password',
        public string $sessionVersion = 'session_version',
    ) {}
}
