<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Orm;

use Closure;
use PHPUnit\Framework\TestCase;
use stdClass;
use Trunk\Orm\Exception\MappingException;
use Trunk\Orm\Mapping\EntityMap;
use Trunk\Orm\Mapping\MapBuilder;
use Trunk\Orm\Mapping\MetadataFactory;
use Trunk\Orm\Mapping\Type;
use Trunk\Tests\Fixtures\Orm\Bad\Broken;
use Trunk\Tests\Fixtures\Orm\Bad\NoConstructor;
use Trunk\Tests\Fixtures\Orm\Customer;
use Trunk\Tests\Fixtures\Orm\CustomerMap;
use Trunk\Tests\Fixtures\Orm\Order;
use Trunk\Tests\Fixtures\Orm\OrderMap;
use Trunk\Tests\Fixtures\Orm\ProfileMap;
use Trunk\Tests\Fixtures\Orm\TagMap;

final class MetadataFactoryTest extends TestCase
{
    public function test_the_fixture_maps_compile_to_metadata_with_resolved_relation_columns(): void
    {
        // Arrange & Act
        $metadata = new MetadataFactory()->build([new CustomerMap(), new OrderMap(), new ProfileMap(), new TagMap()]);
        $customer = $metadata[Customer::class];

        // Assert
        self::assertSame('customers', $customer->table);
        self::assertSame(['id', 'name', 'email', 'status', 'nickname', 'active', 'balance', 'settings', 'created_at', 'deleted_at', 'version'], $customer->selectColumns);
        self::assertTrue($customer->columns['passwordHash']->hidden);
        self::assertTrue($customer->columns['email']->filterable);
        self::assertFalse($customer->columns['balance']->filterable);
        self::assertSame(Type::DateTime, $customer->columns['createdAt']->type);
        self::assertSame('id', $customer->relation('orders')->localColumn);
        self::assertSame('customer_id', $customer->relation('orders')->foreignColumn);
        self::assertSame('customer_id', $metadata[Order::class]->relation('customer')->foreignColumn);
        self::assertSame('deleted_at', $customer->softDeleteColumn()?->column);
        self::assertContains('password_hash', $customer->allColumns());
        self::assertNotContains('password_hash', $customer->selectColumns);
    }

    public function test_every_problem_is_reported_together_with_the_entity_and_a_fix(): void
    {
        // Arrange & Act
        $errors = $this->errorsFor(Broken::class, static function (MapBuilder $m): void {
            $m->table('bad table; DROP TABLE x');
            $m->id();
            $m->string('title');
            $m->string('title', 'other');
            $m->string('missing');
            $m->string('subtitle');
            $m->string('hidden');
            $m->string('bad name', 'ok');
            $m->string('evil', 'a"b');
        });

        // Assert
        self::assertContains(true, array_map(static fn(string $e): bool => str_contains($e, Broken::class), $errors));
        $this->assertError('table() is missing or is not a plain SQL name', $errors);
        $this->assertError('mapped twice', $errors);
        $this->assertError('has no property "missing"', $errors);
        $this->assertError('can be null in PHP but the map does not say ->nullable()', $errors);
        $this->assertError('must be publicly readable', $errors);
        $this->assertError('not a valid property name', $errors);
        $this->assertError('column "a?b" is not a plain SQL name', $errors);
        $this->assertError('a generated id "id" must be a non-readonly nullable property', $errors);
        $this->assertError('constructor parameter "required" has no default', $errors);
    }

    public function test_structural_mistakes_are_rejected(): void
    {
        // Arrange & Act
        $noId = $this->errorsFor(Customer::class, static function (MapBuilder $m): void {
            $m->table('t');
        });
        $twoIds = $this->errorsFor(Customer::class, static function (MapBuilder $m): void {
            $m->table('t');
            $m->id();
            $m->id('name');
        });
        $noConstructor = $this->errorsFor(NoConstructor::class, static function (MapBuilder $m): void {
            $m->table('t');
            $m->id();
        });
        $badScope = $this->errorsFor(Customer::class, static function (MapBuilder $m): void {
            $m->table('t');
            $m->id();
            $m->scope(stdClass::class);
        });

        // Assert
        $this->assertError('declare the primary key', $noId);
        $this->assertError('more than one id()', $twoIds);
        $this->assertError('needs a public constructor', $noConstructor);
        $this->assertError('must be a class implementing', $badScope);
    }

    public function test_relations_must_point_at_mapped_entities_and_real_key_properties(): void
    {
        // Arrange
        $metadataFactory = new MetadataFactory();
        $unmapped = $this->map(Customer::class, static function (MapBuilder $m): void {
            $m->table('customers');
            $m->id();
            $m->hasMany('orders', Order::class, foreignKey: 'customerId');
        });
        $badKey = $this->map(Customer::class, static function (MapBuilder $m): void {
            $m->table('customers');
            $m->id();
            $m->hasMany('orders', Order::class, foreignKey: 'nope');
        });

        // Act
        $errors = [];
        foreach ([[$unmapped], [$badKey, new OrderMap(), new ProfileMap(), new TagMap()]] as $maps) {
            try {
                $metadataFactory->build($maps);
            } catch (MappingException $e) {
                $errors = [...$errors, ...$e->errors];
            }
        }

        // Assert
        $this->assertError('has no entity map', $errors);
        $this->assertError('names a key property that is not mapped', $errors);
    }

    public function test_map_classes_from_config_are_validated_before_use(): void
    {
        // Arrange & Act
        try {
            new MetadataFactory()->fromClasses(['Does\\Not\\Exist', stdClass::class, '../etc/passwd', CustomerMap::class]);
            self::fail('Expected a MappingException.');
        } catch (MappingException $e) {
            // Assert
            $this->assertError('is not a class', $e->errors);
            $this->assertError('must implement', $e->errors);
            self::assertStringNotContainsString('/etc/passwd', implode(' ', array_filter($e->errors, static fn(string $x): bool => !str_contains($x, '?'))));
        }
    }

    public function test_a_hidden_column_needs_a_constructor_default_and_cannot_be_ORM_managed_state(): void
    {
        // Arrange & Act
        $needsDefault = $this->errorsFor(Broken::class, static function (MapBuilder $m): void {
            $m->table('t');
            $m->id();
            $m->string('required')->hidden();
        });
        $hiddenVersion = $this->errorsFor(Customer::class, static function (MapBuilder $m): void {
            $m->table('t');
            $m->id();
            $m->version()->hidden();
        });

        // Assert
        $this->assertError('is not selected, so its constructor parameter needs a default', $needsDefault);
        $this->assertError('cannot be hidden()', $hiddenVersion);
    }

    public function test_snake_case_naming_is_the_default(): void
    {
        // Arrange & Act & Assert
        self::assertSame('created_at', MapBuilder::snake('createdAt'));
        self::assertSame('password_hash', MapBuilder::snake('passwordHash'));
        self::assertSame('id', MapBuilder::snake('id'));
    }
    /**
     * @param class-string                 $entity
     * @param Closure(MapBuilder): void    $define
     */
    private function map(string $entity, Closure $define): EntityMap
    {
        return new class ($entity, $define) implements EntityMap {
            /**
             * @param class-string              $entity
             * @param Closure(MapBuilder): void $define
             */
            public function __construct(private string $entity, private Closure $define) {}

            public function entity(): string
            {
                return $this->entity;
            }

            public function define(MapBuilder $map): void
            {
                ($this->define)($map);
            }
        };
    }

    /**
     * @param class-string              $entity
     * @param Closure(MapBuilder): void $define
     *
     * @return list<string>
     */
    private function errorsFor(string $entity, Closure $define): array
    {
        try {
            new MetadataFactory()->build([$this->map($entity, $define)]);
        } catch (MappingException $e) {
            return $e->errors;
        }

        return [];
    }

    /**
     * @param list<string> $errors
     */
    private function assertError(string $needle, array $errors): void
    {
        self::assertNotSame([], array_filter($errors, static fn(string $e): bool => str_contains($e, $needle)), $needle . ' not found in: ' . implode(' | ', $errors));
    }
}
