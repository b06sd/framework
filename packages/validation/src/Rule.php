<?php

declare(strict_types=1);

namespace Trunk\Validation;

/**
 * One check on one value. Rules are attributes on a request class's constructor parameters, so a rule
 * you write yourself is a `#[Attribute]` class implementing this interface, with public promoted
 * constructor parameters that are plain values (the build turns each rule into a `new` expression).
 *
 * The value has already been checked against the parameter's declared type. Return `null` when it is
 * acceptable, a `Violation` when it is not. Never put the submitted value in the message.
 *
 * @api
 */
interface Rule
{
    public function check(mixed $value, Context $context): ?Violation;
}
