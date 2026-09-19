<?php

declare(strict_types=1);

namespace Trunk\Queue\Worker;

enum Outcome: string
{
    case Succeeded = 'succeeded';
    case Retried = 'retried';
    case Failed = 'failed';
    /** The job timed out and another worker owns it now. */
    case Lost = 'lost';
}
