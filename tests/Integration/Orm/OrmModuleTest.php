<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Orm;

use PHPUnit\Framework\TestCase;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Container\ContainerBuilder;
use Trunk\Database\DatabaseModule;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Orm\OrmModule;
use Trunk\Orm\UnitOfWork\EntityManager;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Orm\Customer;
use Trunk\Tests\Fixtures\Orm\CustomerMap;
use Trunk\Tests\Fixtures\Orm\OrderMap;
use Trunk\Tests\Fixtures\Orm\ProfileMap;
use Trunk\Tests\Fixtures\Orm\TagMap;
use Trunk\Tests\Support\ContainerModes;

final class OrmModuleTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-orm-module-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->directory);
    }

    public function test_the_build_writes_generated_code_and_production_and_development_behave_the_same(): void
    {
        // Arrange
        $configuration = new Configuration(['orm' => ['mode' => 'development', 'maps' => $this->maps(), 'build' => $this->directory]]);
        $contribution = new OrmModule()->plan(new BuildContext(new ModuleManifest([]), new Runtime(Environment::Production, false, $this->directory), $configuration));
        foreach ($contribution->writers as $write) {
            $write($this->directory);
        }
        $results = [];

        foreach (['development', 'compiled'] as $mode) {
            $containers = new ContainerModes()->both(
                static function (ContainerBuilder $b): void {
                    new DatabaseModule()->register($b);
                    new OrmModule()->register($b);
                },
                ['database' => ['default' => 'main', 'log_queries' => false, 'migrations' => $this->directory, 'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']]], 'orm' => ['mode' => $mode, 'maps' => $this->maps(), 'build' => $this->directory]],
            );

            foreach ($containers as $container => $root) {
                // Act
                $scope = $root->beginScope();
                $manager = $scope->get(EntityManager::class);
                self::assertInstanceOf(EntityManager::class, $manager);
                $orm = new \Trunk\Tests\Support\OrmHarness();
                $second = new EntityManager($orm->connection, $manager->registry());
                $second->persist(new Customer(name: 'Ada', email: 'a@x.dev', settings: ['k' => 1]));
                $second->flush();
                $found = $second->repository(Customer::class)->query()->where('email', 'a@x.dev')->first();

                // Assert
                self::assertSame($manager, $scope->get(EntityManager::class), $mode . '/' . $container);
                self::assertNotSame($manager, $root->beginScope()->get(EntityManager::class), 'a fresh unit of work per scope');
                self::assertNotNull($found);
                $results[$mode . '/' . $container] = $manager->toArray($found);
            }
        }

        self::assertFileExists($this->directory . '/orm.php');
        self::assertCount(1, array_unique(array_map(static fn(array $r): string => (string) json_encode($r), $results)));
    }

    public function test_a_broken_map_fails_the_build_with_every_error_and_writes_nothing(): void
    {
        // Arrange
        $configuration = new Configuration(['orm' => ['mode' => 'development', 'maps' => ['Missing\\ThingMap', CustomerMap::class], 'build' => $this->directory]]);

        // Act & Assert
        try {
            new OrmModule()->plan(new BuildContext(new ModuleManifest([]), new Runtime(Environment::Production, false, $this->directory), $configuration));
            self::fail('Expected a CompilationException.');
        } catch (\Trunk\Compiler\Exception\CompilationException $e) {
            self::assertNotEmpty($e->errors);
            self::assertFileDoesNotExist($this->directory . '/orm.php');
        }
    }

    public function test_production_refuses_to_run_without_a_build(): void
    {
        // Arrange
        $registry = new \Trunk\Orm\Mapping\ConfiguredRegistry(new Configuration(['orm' => ['mode' => 'compiled', 'maps' => [], 'build' => $this->directory]]));

        // Act & Assert
        $this->expectException(\Trunk\Orm\Exception\OrmException::class);
        $this->expectExceptionMessage('trunk build');
        $registry->metadata(Customer::class);
    }

    /**
     * @return list<class-string>
     */
    private function maps(): array
    {
        return [CustomerMap::class, OrderMap::class, ProfileMap::class, TagMap::class];
    }
}
