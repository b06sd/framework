<?php

declare(strict_types=1);

namespace Trunk\Tusk\Runtime;

use Stringable;

/**
 * A string already safe for its output context; escaping is skipped for it.
 *
 * @api
 */
final readonly class SafeHtml implements Stringable
{
    public function __construct(public string $html) {}

    public function __toString(): string
    {
        return $this->html;
    }
}
