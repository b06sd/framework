<?php

declare(strict_types=1);

namespace Trunk\Queue\Job;

use Trunk\Queue\Exception\UnknownJob;

interface JobRegistry
{
    /**
     * @throws UnknownJob
     */
    public function definition(string $name): JobDefinition;

    /**
     * @return list<string>
     */
    public function names(): array;
}
