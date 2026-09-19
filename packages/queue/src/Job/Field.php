<?php

declare(strict_types=1);

namespace Trunk\Queue\Job;

use BackedEnum;

/**
 * One constructor parameter of a job, as compiled.
 */
final readonly class Field
{
    /**
     * @param class-string<BackedEnum>|null $enum
     */
    public function __construct(
        public string $name,
        public PayloadType $type,
        public bool $nullable,
        public bool $optional,
        public ?string $enum = null,
    ) {}
}
