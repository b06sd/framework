<?php

declare(strict_types=1);

namespace Trunk\Doctor;

/**
 * @api
 */
final readonly class DoctorFinding
{
    private function __construct(
        public bool $ok,
        public ?string $message = null,
        public ?string $fix = null,
    ) {}

    public static function ok(): self
    {
        return new self(true);
    }

    /**
     * @param string      $message what is wrong, read directly by whoever ran `trunk doctor`
     * @param string|null $fix     the command or change that would resolve it, if there is one
     */
    public static function problem(string $message, ?string $fix = null): self
    {
        return new self(false, $message, $fix);
    }
}
