<?php

declare(strict_types=1);

namespace Trunk\Validation;

/**
 * A rule that can make a missing value an error (`Required`, `RequiredIf`). Every other rule only
 * looks at values that are present. `whenMissing()` returns the violation to report if the field was
 * not sent (or was null), or `null` when it may be left out.
 *
 * @api
 */
interface PresenceRule extends Rule
{
    public function whenMissing(Context $context): ?Violation;
}
