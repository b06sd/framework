<?php

declare(strict_types=1);

namespace Trunk\Tusk\Runtime;

use Closure;

/**
 * What a compiled `.tusk.php` file returns: an optional parent layout, the content it provides
 * for slots, and its own body (used when it is the outermost template).
 */
final readonly class CompiledTemplate
{
    /**
     * @param array<string, Closure(TemplateContext, array<string, mixed>): void> $slots
     * @param Closure(TemplateContext, array<string, mixed>): void                $main
     */
    public function __construct(
        public ?string $parent,
        public array $slots,
        public Closure $main,
    ) {}
}
