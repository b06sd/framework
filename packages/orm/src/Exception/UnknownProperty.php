<?php

declare(strict_types=1);

namespace Trunk\Orm\Exception;

use InvalidArgumentException;

/**
 * A query named a property that is not mapped, or not allowed for that use (filterable/sortable).
 * The offending name is deliberately not echoed for untrusted input beyond a bounded, printable form.
 *
 * @api
 */
final class UnknownProperty extends InvalidArgumentException
{
    public static function for(string $entity, string $property, string $use): self
    {
        $safe = preg_replace('/[^A-Za-z0-9_.\-]/', '?', substr($property, 0, 40)) ?? '?';

        return new self(\sprintf('"%s" is not a %s property of %s. Declare it in the entity map (->%s()) to allow it.', $safe, $use, $entity, $use === 'mapped' ? 'string()/int()/…' : $use));
    }
}
