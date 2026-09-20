<?php

declare(strict_types=1);

namespace Trunk\Validation\Rules;

use Attribute;
use InvalidArgumentException;
use Trunk\Validation\Context;
use Trunk\Validation\Rule;
use Trunk\Validation\Violation;

/**
 * An IP address: `#[Ip]` (either), `#[Ip(4)]` or `#[Ip(6)]`.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Ip implements Rule
{
    public function __construct(public ?int $version = null)
    {
        if ($version !== null && $version !== 4 && $version !== 6) {
            throw new InvalidArgumentException('Ip takes 4, 6 or nothing.');
        }
    }

    public function check(mixed $value, Context $context): ?Violation
    {
        $flag = match ($this->version) {
            4 => \FILTER_FLAG_IPV4,
            6 => \FILTER_FLAG_IPV6,
            default => \FILTER_FLAG_IPV4 | \FILTER_FLAG_IPV6,
        };

        return \is_string($value) && filter_var($value, \FILTER_VALIDATE_IP, $flag) !== false ? null : new Violation('ip', 'Must be a valid IP address.');
    }
}
