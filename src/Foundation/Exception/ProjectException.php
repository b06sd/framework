<?php

declare(strict_types=1);

namespace Trunk\Foundation\Exception;

use RuntimeException;

/**
 * A problem with the project itself (trunk.php, config files, missing build). The message says
 * what to do about it.
 */
final class ProjectException extends RuntimeException {}
