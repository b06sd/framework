<?php

declare(strict_types=1);

namespace Trunk\Orm\Mapping;

use Trunk\Foundation\Configuration;
use Trunk\Orm\Compiler\OrmArtifact;
use Trunk\Orm\Exception\OrmException;

/**
 * Chooses the registry from configuration, explicitly (like Tusk's loader):
 *   orm.mode = "development" -> orm.maps (list of EntityMap classes)
 *   orm.mode = "compiled"    -> orm.build (directory holding orm.php from `trunk build`)
 */
final class ConfiguredRegistry implements MappingRegistry
{
    private ?MappingRegistry $inner = null;

    public function __construct(private readonly Configuration $configuration) {}

    public function metadata(string $class): EntityMetadata
    {
        return $this->inner()->metadata($class);
    }

    public function mapper(string $class): Mapper
    {
        return $this->inner()->mapper($class);
    }

    public function entities(): array
    {
        return $this->inner()->entities();
    }

    private function inner(): MappingRegistry
    {
        return $this->inner ??= match ($mode = $this->configuration->string('orm.mode')) {
            'compiled' => OrmArtifact::load($this->configuration->string('orm.build') . '/orm.php'),
            'development' => new DevelopmentRegistry(array_values(array_filter($this->configuration->array('orm.maps'), is_string(...)))),
            default => throw new OrmException(\sprintf('orm.mode must be "development" or "compiled", "%s" given.', $mode)),
        };
    }
}
