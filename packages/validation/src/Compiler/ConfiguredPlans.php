<?php

declare(strict_types=1);

namespace Trunk\Validation\Compiler;

use LogicException;
use Trunk\Compiler\ArtifactLoader;
use Trunk\Foundation\Configuration;
use Trunk\Validation\Plan\Plan;
use Trunk\Validation\Plans;

/**
 * Chooses explicitly from configuration, like the ORM: `validation.mode` is `development` (read the
 * classes) or `compiled` (load `validation.php` from the build directory).
 */
final class ConfiguredPlans implements Plans
{
    private ?Plans $inner = null;

    public function __construct(private readonly Configuration $configuration) {}

    public function plan(string $class): Plan
    {
        return ($this->inner ??= match ($mode = $this->configuration->string('validation.mode')) {
            'compiled' => ArtifactLoader::load($this->configuration->string('validation.build') . '/validation.php', CompiledValidation::class, 'validation plans'),
            'development' => new ReflectionPlans(),
            default => throw new LogicException(\sprintf('validation.mode must be "development" or "compiled", "%s" given.', $mode)),
        })->plan($class);
    }
}
