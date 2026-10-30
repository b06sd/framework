<?php

declare(strict_types=1);

namespace Trunk\Router\Attribute;

use Attribute;

/**
 * The parameter is the validated query string: `public function index(#[Query] Filters $filters)`.
 * Values are read as text, strictly, and invalid input is a 422 before the controller is called.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Query {}
