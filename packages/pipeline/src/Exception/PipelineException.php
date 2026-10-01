<?php

declare(strict_types=1);

namespace Trunk\Pipeline\Exception;

use LogicException;

/**
 * Misuse of the pipeline API (a pipeline class that does not implement Pipeline, an unknown run id).
 *
 * @api
 */
final class PipelineException extends LogicException {}
