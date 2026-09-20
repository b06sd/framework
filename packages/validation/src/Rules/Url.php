<?php

declare(strict_types=1);

namespace Trunk\Validation\Rules;

use Attribute;
use Trunk\Validation\Context;
use Trunk\Validation\Rule;
use Trunk\Validation\Violation;

/**
 * An absolute URL with a host, at most 2048 characters, and only the listed schemes (`http` and
 * `https` unless you say otherwise), so `javascript:` and `data:` links never pass.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Url implements Rule
{
    /**
     * @param list<string> $schemes
     */
    public function __construct(public array $schemes = ['http', 'https']) {}

    public function check(mixed $value, Context $context): ?Violation
    {
        $parts = \is_string($value) && \strlen($value) <= 2048 && preg_match('/[\x00-\x20\x7F]/', $value) !== 1 ? parse_url($value) : false;
        $valid = \is_array($parts) && isset($parts['scheme'], $parts['host']) && $parts['host'] !== '' && \in_array(strtolower($parts['scheme']), $this->schemes, true);

        return $valid ? null : new Violation('url', 'Must be a valid URL.');
    }
}
