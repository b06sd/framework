<?php

declare(strict_types=1);

namespace Trunk\Orm\Exception;

use LogicException;

/**
 * Misuse of the ORM API (an unmapped entity, an unmanaged entity, a wrong argument).
 *
 * @api
 */
final class OrmException extends LogicException {}
