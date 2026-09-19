<?php

declare(strict_types=1);

namespace Trunk\Orm;

use Psr\Container\ContainerInterface;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Build\BuildContribution;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Container\Definition\TaggedReference;
use Trunk\Container\Lifetime;
use Trunk\Contracts\BuildContributor;
use Trunk\Contracts\Module;
use Trunk\Contracts\ModuleDependencies;
use Trunk\Database\Connection\Connection;
use Trunk\Database\DatabaseModule;
use Trunk\Foundation\Configuration;
use Trunk\Orm\Compiler\OrmCodeGenerator;
use Trunk\Orm\Exception\MappingException;
use Trunk\Orm\Mapping\ConfiguredRegistry;
use Trunk\Orm\Mapping\MappingRegistry;
use Trunk\Orm\Mapping\MetadataFactory;
use Trunk\Orm\UnitOfWork\EntityManager;
use Trunk\Support\FileWriter;

/**
 * Registers the ORM. Inject `EntityManager` (one per request or job) and get repositories from
 * it. `trunk build` validates every entity map and writes generated metadata and hydrators to
 * build/orm.php; production runs only that generated code.
 *
 * Global scopes are services tagged `orm.scope`.
 *
 * @api
 */
final class OrmModule implements Module, BuildContributor, ModuleDependencies
{
    public function requires(): array
    {
        return [DatabaseModule::class];
    }

    public function after(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void
    {
        $builder->service(MappingRegistry::class, ConfiguredRegistry::class, [new Reference(Configuration::class)]);
        $builder->service(EntityManager::class, EntityManager::class, [
            new Reference(Connection::class),
            new Reference(MappingRegistry::class),
            new TaggedReference('orm.scope'),
        ], Lifetime::Scoped);
    }

    public function boot(ContainerInterface $container): void {}

    public function plan(BuildContext $context): BuildContribution
    {
        if (!$context->configuration->has('orm.maps')) {
            return new BuildContribution();
        }

        $maps = array_values(array_filter($context->configuration->array('orm.maps'), is_string(...)));

        try {
            $source = new OrmCodeGenerator()->generate(new MetadataFactory()->fromClasses($maps));
        } catch (MappingException $e) {
            throw new CompilationException($e->errors);
        }

        return new BuildContribution([], [static function (string $directory) use ($source): void {
            new FileWriter()->write($directory . '/orm.php', $source);
        }]);
    }
}
