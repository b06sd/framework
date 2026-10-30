<?php

declare(strict_types=1);

namespace Trunk\Http\Routing;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Turns a request into the object a `#[Body]` or `#[Query]` parameter asks for. The validation
 * capability provides it; invalid input throws `Trunk\Error\ValidationException` (a 422).
 *
 * @api
 */
interface InputBinder
{
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param bool            $query true for `#[Query]` (the query string), false for `#[Body]`
     *
     * @return T
     */
    public function bind(string $class, ServerRequestInterface $request, bool $query): object;
}
