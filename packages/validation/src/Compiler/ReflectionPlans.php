<?php

declare(strict_types=1);

namespace Trunk\Validation\Compiler;

use Trunk\Validation\Plan\Plan;
use Trunk\Validation\Plans;

/**
 * Development: plans are read from the classes on first use (and remembered), so a change to a
 * request class is picked up without a build.
 */
final class ReflectionPlans implements Plans
{
    /** @var array<class-string, Plan> */
    private array $plans = [];

    public function __construct(private readonly PlanBuilder $builder = new PlanBuilder()) {}

    public function plan(string $class): Plan
    {
        return $this->plans[$class] ??= $this->builder->plan($class);
    }
}
