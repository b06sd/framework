<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Queue;

/**
 * A singleton that outlives job scopes, so tests can see what each scope did.
 */
final class Ledger
{
    /** @var list<int> */
    public array $entries = [];
}
