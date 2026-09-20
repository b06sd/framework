<?php

declare(strict_types=1);

namespace Trunk\Validation;

/**
 * A failed check: a stable rule code (`email`, `max_length`, ...) and a message that is safe to show.
 *
 * @api
 */
final readonly class Violation
{
    public function __construct(public string $rule, public string $message) {}
}
