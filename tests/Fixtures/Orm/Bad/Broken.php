<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm\Bad;

/**
 * Deliberately mismatched with the maps written against it in MetadataFactoryTest.
 */
final class Broken
{
    private string $hidden = '';

    public function __construct(
        public readonly ?int $id,
        public string $required,
        public string $title = '',
        public ?string $subtitle = null,
    ) {}

    public function hidden(): string
    {
        return $this->hidden;
    }
}
