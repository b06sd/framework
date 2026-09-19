<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax;

final readonly class Token
{
    /**
     * @param array<string, string> $attributes
     */
    public function __construct(
        public TokenKind $kind,
        public int $line,
        public string $text = '',
        public string $name = '',
        public array $attributes = [],
        public bool $selfClosing = false,
    ) {}
}
