<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Trunk\Orm\UnitOfWork\ChangeListener;
use Trunk\Orm\UnitOfWork\Changes;

/**
 * Keeps every batch of changes a flush reports.
 */
final class RecordingChangeListener implements ChangeListener
{
    /** @var list<Changes> */
    public array $batches = [];

    public function changed(Changes $changes): void
    {
        $this->batches[] = $changes;
    }
}
