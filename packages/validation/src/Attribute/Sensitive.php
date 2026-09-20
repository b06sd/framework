<?php

declare(strict_types=1);

namespace Trunk\Validation\Attribute;

use Attribute;

/**
 * Marks a field that must never be shown again after a failed submission (passwords, tokens).
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Sensitive {}
