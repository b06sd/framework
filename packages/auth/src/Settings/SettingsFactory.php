<?php

declare(strict_types=1);

namespace Trunk\Auth\Settings;

use Trunk\Foundation\Configuration;

/**
 * Builds the settings objects from configuration for the container, so services can ask for exactly
 * the group they use (`SessionSettings`, `UserSettings`, ...).
 */
final readonly class SettingsFactory
{
    public function settings(Configuration $configuration): AuthSettings
    {
        return AuthSettings::fromConfiguration($configuration);
    }

    public function password(AuthSettings $settings): PasswordSettings
    {
        return $settings->password;
    }

    public function users(AuthSettings $settings): UserSettings
    {
        return $settings->users;
    }

    public function session(AuthSettings $settings): SessionSettings
    {
        return $settings->session;
    }

    public function tokens(AuthSettings $settings): TokenSettings
    {
        return $settings->tokens;
    }

    public function throttle(AuthSettings $settings): ThrottleSettings
    {
        return $settings->throttle;
    }
}
