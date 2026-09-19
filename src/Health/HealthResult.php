<?php

declare(strict_types=1);

namespace Trunk\Health;

/**
 * @api
 */
final readonly class HealthResult
{
    private function __construct(
        public bool $up,
        public ?string $detail = null,
    ) {}

    public static function up(): self
    {
        return new self(true);
    }

    /**
     * @param string|null $detail for the log and the development view only
     */
    public static function down(?string $detail = null): self
    {
        return new self(false, $detail);
    }
}
