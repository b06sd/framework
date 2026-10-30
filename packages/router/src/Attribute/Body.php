<?php

declare(strict_types=1);

namespace Trunk\Router\Attribute;

use Attribute;

/**
 * The parameter is the validated request body: `public function store(#[Body] Signup $signup)`. The
 * class is a request class (see validation); JSON and form bodies are read as `RequestValidator`
 * reads them, and invalid input is a 422 before the controller is called.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Body {}
