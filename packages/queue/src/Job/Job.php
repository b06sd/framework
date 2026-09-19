<?php

declare(strict_types=1);

namespace Trunk\Queue\Job;

/**
 * Marks a class as a queueable job. The constructor is the payload (ints, floats, strings, bools,
 * arrays, backed enums and DateTimeImmutable, all optionally nullable); handle() receives its
 * dependencies from the container, opened as a fresh scope for each job.
 *
 * There is no method to implement here: `trunk build` checks the shape of `handle()` and of the
 * constructor and rejects anything unsafe, with the fix.
 *
 * @api
 */
interface Job {}
