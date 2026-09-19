<?php

declare(strict_types=1);

namespace Trunk\Http\Error;

/**
 * The application's custom error renderers, collected from the `http.error_renderer` tag.
 */
final readonly class ErrorRenderers
{
    /**
     * @param iterable<ErrorRenderer> $renderers
     */
    public function __construct(private iterable $renderers = []) {}

    /**
     * @return list<ErrorRenderer>
     */
    public function all(): array
    {
        return array_values([...$this->renderers]);
    }
}
