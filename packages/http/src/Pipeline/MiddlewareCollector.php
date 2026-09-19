<?php

declare(strict_types=1);

namespace Trunk\Http\Pipeline;

use InvalidArgumentException;
use Psr\Http\Server\MiddlewareInterface;
use Trunk\Support\ClassName;

/**
 * Ordered list of global middleware class names, each resolved from the container at request time.
 *
 * @api
 */
final class MiddlewareCollector
{
    /** @var list<string> */
    private array $ids = [];

    /**
     * @param class-string<MiddlewareInterface> $class also the container id
     */
    public function add(string $class): void
    {
        if (!ClassName::isValid($class) || !is_subclass_of($class, MiddlewareInterface::class)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a PSR-15 middleware class.', $class));
        }

        $this->ids[] = $class;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return $this->ids;
    }
}
