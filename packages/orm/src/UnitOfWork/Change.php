<?php

declare(strict_types=1);

namespace Trunk\Orm\UnitOfWork;

/**
 * One entity written by a flush. Values are keyed by property name, in their stored form (dates and
 * decimals as text, an enum as its value), so they can be written as they are into an audit row or a
 * message. An insert has only `after`, a delete only `before`; an update has, in both, only the
 * properties that changed. A hidden() property is listed with the value `Change::HIDDEN`.
 *
 * @api
 */
final readonly class Change
{
    public const string HIDDEN = '[hidden]';

    /**
     * @param class-string                               $class
     * @param array<string, string|int|float|bool|null> $before
     * @param array<string, string|int|float|bool|null> $after
     * @param bool                                       $soft   a delete that only set the soft-delete column
     */
    public function __construct(
        public ChangeKind $kind,
        public object $entity,
        public string $class,
        public int|string $id,
        public array $before = [],
        public array $after = [],
        public bool $soft = false,
    ) {}

    /**
     * @return list<string> the properties this change is about
     */
    public function properties(): array
    {
        return array_keys([...$this->before, ...$this->after]);
    }
}
