<?php

declare(strict_types=1);

namespace Trunk\Foundation;

/**
 * @api
 */
enum Environment: string
{
    case Production = 'production';
    case Local = 'local';
    case Testing = 'testing';

    /**
     * Unknown or missing values fall back to Production (secure by default).
     */
    public static function fromString(?string $value): self
    {
        if ($value === null) {
            return self::Production;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::Production;
    }
}
