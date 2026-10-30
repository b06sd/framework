<?php

declare(strict_types=1);

namespace Trunk\Auth\Jwt;

use RuntimeException;

/**
 * A token was refused. The message is the reason, for logs ("expired", "wrong audience"); a client
 * only ever sees a 401.
 *
 * @api
 */
final class InvalidJwt extends RuntimeException {}
