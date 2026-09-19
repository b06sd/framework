<?php

declare(strict_types=1);

namespace Trunk\Container\Definition;

use Trunk\Container\Exception\ContainerException;

/**
 * A constructor argument read from Configuration when the service is created.
 *
 * @api
 */
final readonly class ConfigValue implements Argument
{
    /**
     * @param string|null $type 'string', 'int', 'bool' or 'array'; null returns the value as-is
     */
    public function __construct(public string $key, public ?string $type = null)
    {
        if ($key === '' || preg_match('/^[A-Za-z0-9_.\-]+$/D', $key) !== 1) {
            throw new ContainerException(\sprintf('"%s" is not a valid configuration key.', $key));
        }

        if ($type !== null && !\in_array($type, ['string', 'int', 'bool', 'array'], true)) {
            throw new ContainerException(\sprintf('Configuration type "%s" is not supported.', $type));
        }
    }
}
