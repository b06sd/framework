<?php

declare(strict_types=1);

namespace Trunk\Compiler\Exception;

use RuntimeException;

/**
 * A build artifact is missing or is not what `trunk build` writes. The message tells the developer
 * what to run; it names the artifact, never a filesystem path.
 */
final class ArtifactException extends RuntimeException {}
