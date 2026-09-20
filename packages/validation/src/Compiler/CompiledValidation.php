<?php

declare(strict_types=1);

namespace Trunk\Validation\Compiler;

use LogicException;
use Trunk\Validation\Plan\Plan;
use Trunk\Validation\Plans;

/**
 * What `build/validation.php` returns: every request class's plan, ready to run.
 */
final readonly class CompiledValidation implements Plans
{
    /**
     * @param array<class-string, Plan> $plans
     */
    public function __construct(private array $plans) {}

    public function plan(string $class): Plan
    {
        return $this->plans[$class] ?? throw new LogicException(\sprintf('%s is not a request class. List it in config/validation.php and run `trunk build`.', $class));
    }
}
