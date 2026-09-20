<?php

declare(strict_types=1);

namespace Trunk\Orm\Exception;

use InvalidArgumentException;
use Trunk\Error\ErrorCode;
use Trunk\Error\PublicError;

/**
 * A query named a property that is not mapped, or not allowed for that use (filterable/sortable).
 * The offending name is deliberately not echoed for untrusted input beyond a bounded, printable form.
 *
 * When the name came from a request (`filterable`, `sortable`) this is the client's mistake: a 400 with a
 * fixed, generic message. When it came from your own code (`mapped`, `relation`, e.g. `with('typo')`)
 * it is a bug in the application and stays a 500, so monitoring sees it.
 *
 * @api
 */
final class UnknownProperty extends InvalidArgumentException implements PublicError
{
    public function __construct(string $message, private readonly bool $fromRequest = false)
    {
        parent::__construct($message);
    }

    public static function for(string $entity, string $property, string $use): self
    {
        $safe = preg_replace('/[^A-Za-z0-9_.\-]/', '?', substr($property, 0, 40)) ?? '?';

        return new self(\sprintf('"%s" is not a %s property of %s. Declare it in the entity map (->%s()) to allow it.', $safe, $use, $entity, $use === 'mapped' ? 'string()/int()/…' : $use), $use === 'filterable' || $use === 'sortable');
    }

    public function errorCode(): string
    {
        return ($this->fromRequest ? ErrorCode::BadRequest : ErrorCode::InternalError)->value;
    }

    public function statusCode(): int
    {
        return $this->fromRequest ? 400 : 500;
    }

    public function publicMessage(): string
    {
        return $this->fromRequest ? 'The filter or sort is not valid.' : 'An unexpected error occurred.';
    }

    public function details(): array
    {
        return [];
    }
}
