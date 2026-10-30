<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\OpenApi;

use DateTimeImmutable;
use Trunk\Validation\Attribute\ListOf;

final readonly class OrderView
{
    /**
     * @param list<LineView> $lines
     */
    public function __construct(
        public int $id,
        public OrderStatus $status,
        public ?string $note,
        public DateTimeImmutable $placedAt,
        #[ListOf(LineView::class)]
        public array $lines,
        public ?self $replaces = null,
    ) {}
}
