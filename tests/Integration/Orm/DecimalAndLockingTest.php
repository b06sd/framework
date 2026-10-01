<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Orm;

use BcMath\Number;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Connection\QueryLog;
use Trunk\Database\Exception\InvalidQueryException;
use Trunk\Database\Schema\Blueprint;
use Trunk\Database\Schema\Schema;
use Trunk\Orm\Compiler\OrmArtifact;
use Trunk\Orm\Compiler\OrmCodeGenerator;
use Trunk\Orm\Exception\HydrationException;
use Trunk\Orm\Exception\InvalidFilter;
use Trunk\Orm\Exception\MappingException;
use Trunk\Orm\Exception\OrmException;
use Trunk\Orm\Exception\StaleEntity;
use Trunk\Orm\Mapping\Convert;
use Trunk\Orm\Mapping\DevelopmentRegistry;
use Trunk\Orm\Mapping\EntityMap;
use Trunk\Orm\Mapping\MapBuilder;
use Trunk\Orm\Mapping\MappingRegistry;
use Trunk\Orm\Mapping\MetadataFactory;
use Trunk\Orm\UnitOfWork\EntityManager;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Orm\Order;
use Trunk\Tests\Fixtures\Orm\StockItem;
use Trunk\Tests\Fixtures\Orm\StockItemMap;
use Trunk\Tests\Support\DatabaseHarness;

/**
 * Exact decimals (money and quantities never pass through a float) and row locking through the ORM,
 * with the generated and the development mapper alike.
 */
final class DecimalAndLockingTest extends TestCase
{
    private string $directory;

    private Connection $connection;

    private QueryLog $log;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-dec-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->log = new QueryLog();
        $this->connection = new DatabaseHarness()->sqlite($this->log);
        new Schema($this->connection)->create('stock_items', function (Blueprint $t): void {
            $t->id();
            $t->string('sku');
            $t->decimal('price', 12, 2);
            $t->decimal('on_hand', 14, 3);
            $t->decimal('cost', 14, 4)->nullable();
        });
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->directory);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mappers(): iterable
    {
        yield 'generated' => ['generated'];
        yield 'development' => ['development'];
    }

    #[DataProvider('mappers')]
    public function test_decimals_round_trip_exactly_at_the_column_scale(string $mapper): void
    {
        // Arrange
        $manager = $this->manager($mapper);
        $manager->persist(new StockItem(sku: 'A-1', price: new Number('19.99'), onHand: new Number('2.5')));
        $manager->flush();

        // Act
        $item = $this->manager($mapper)->repository(StockItem::class)->query()->where('sku', 'A-1')->first();

        // Assert
        self::assertInstanceOf(StockItem::class, $item);
        self::assertSame(['19.99', '2.500', null], [$item->price->value, $item->onHand->value, $item->cost?->value]);
        self::assertSame('59.97', ($item->price * 3)->value, 'arithmetic is exact, not 59.970000000000006');
        self::assertSame(0, (new Number('0.1') + new Number('0.2'))->compare(new Number('0.3')), '0.1 + 0.2 is exactly 0.3');
    }

    #[DataProvider('mappers')]
    public function test_a_value_with_more_digits_than_the_column_holds_is_refused_not_rounded(string $mapper): void
    {
        // Arrange
        $manager = $this->manager($mapper);
        $manager->persist(new StockItem(sku: 'A-1', price: new Number('19.999')));

        // Act
        try {
            $manager->flush();
            self::fail('Expected the save to be refused.');
        } catch (OrmException $e) {
            // Assert
            self::assertStringContainsString('StockItem::$price is 19.999', $e->getMessage());
            self::assertStringContainsString('->round(2)', $e->getMessage());
        }

        self::assertSame(0, $this->connection->table('stock_items')->count(), 'nothing was written');
    }

    #[DataProvider('mappers')]
    public function test_trailing_zeros_are_not_a_change(string $mapper): void
    {
        // Arrange
        $this->connection->table('stock_items')->insert(['sku' => 'A-1', 'price' => '19.99', 'on_hand' => '2.5']);
        $manager = $this->manager($mapper);
        $item = $manager->repository(StockItem::class)->query()->first();
        self::assertInstanceOf(StockItem::class, $item);
        $this->log->clear();

        // Act
        $item->price = new Number('19.990');
        $item->onHand = new Number('2.5');
        $manager->flush();

        // Assert
        self::assertSame([], $this->log->entries(), 'the same amount with more trailing zeros writes nothing');
    }

    public function test_query_values_are_exact_and_request_input_must_fit_the_column(): void
    {
        // Arrange
        $this->connection->table('stock_items')->insert(['sku' => 'A-1', 'price' => '19.99', 'on_hand' => '1']);
        $repository = $this->manager('generated')->repository(StockItem::class);

        // Act
        $above = $repository->query()->where('price', '>', '19.985')->count();
        $equal = $repository->query()->where('price', new Number('19.99'))->count();
        $input = $repository->input(['price' => '12.30'], ['price']);

        // Assert
        self::assertSame([1, 1], [$above, $equal], 'a query value may have more digits than the column');
        self::assertInstanceOf(Number::class, $input['price']);
        self::assertSame('12.30', $input['price']->value);

        foreach (['12.345', '1e3', ' 12', 'twelve', ''] as $bad) {
            try {
                $repository->input(['price' => $bad], ['price']);
                self::fail(\sprintf('"%s" should be refused', $bad));
            } catch (InvalidFilter) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_database_values_are_read_exactly_or_refused(): void
    {
        // Arrange: what MySQL/PostgreSQL (strings), SQLite (int, float) can hand back
        $accepted = [['12.5', '12.50'], [12, '12.00'], [12.5, '12.50'], ['-0.5', '-0.50'], ['12.300', '12.30'], [0.1, '0.10']];
        $refused = ['12.345', '1e3', ' 1', '', 'abc', true, \NAN, \INF, 1.0E+25, [1]];

        // Act & Assert
        foreach ($accepted as [$value, $expected]) {
            self::assertSame($expected, Convert::decimal($value, 2, 'Item', 'price')->value);
        }

        foreach ($refused as $value) {
            try {
                Convert::decimal($value, 2, 'Item', 'price');
                self::fail('Expected ' . get_debug_type($value) . ' to be refused');
            } catch (HydrationException $e) {
                self::assertStringNotContainsString('12.345', $e->getMessage(), 'the value itself is never echoed');
            }
        }
    }

    public function test_the_map_is_checked_at_build_time(): void
    {
        // Arrange: a float property mapped as decimal, and a scale no database supports
        $floatProperty = new class implements EntityMap {
            public function entity(): string
            {
                return Order::class;
            }

            public function define(MapBuilder $map): void
            {
                $map->table('orders');
                $map->id();
                $map->int('customerId');
                $map->decimal('total', 31);
                $map->string('note')->nullable();
            }
        };

        // Act
        try {
            new MetadataFactory()->build([$floatProperty]);
            self::fail('Expected a mapping error.');
        } catch (MappingException $e) {
            $errors = implode("\n", $e->errors);

            // Assert
            self::assertStringContainsString('decimal "total" needs a scale from 0 to 30', $errors);
            self::assertStringContainsString('decimal "total" must be typed BcMath\Number (exact; a float would lose cents)', $errors);
        }
    }

    #[DataProvider('mappers')]
    public function test_lock_for_update_needs_a_transaction_and_refuses_counting(string $mapper): void
    {
        // Arrange
        $this->connection->table('stock_items')->insert(['sku' => 'A-1', 'price' => '1', 'on_hand' => '5']);
        $query = $this->manager($mapper)->repository(StockItem::class)->query()->where('sku', 'A-1')->lockForUpdate();

        // Act & Assert
        self::assertInstanceOf(StockItem::class, $this->connection->transaction(static fn(): ?StockItem => $query->first()));

        foreach (['first() outside a transaction' => static fn(): mixed => $query->first(), 'count()' => fn(): mixed => $this->connection->transaction(static fn(): int => $query->count())] as $case => $attempt) {
            try {
                $attempt();
                self::fail($case . ' should be refused');
            } catch (InvalidQueryException $e) {
                self::assertStringContainsString('lockForUpdate()', $e->getMessage(), $case);
            }
        }
    }

    #[DataProvider('mappers')]
    public function test_locking_an_entity_loaded_earlier_refuses_stale_values(string $mapper): void
    {
        // Arrange: this unit of work loaded the item, then someone else changed the row
        $this->connection->table('stock_items')->insert(['sku' => 'A-1', 'price' => '1', 'on_hand' => '5']);
        $manager = $this->manager($mapper);
        $loaded = $manager->repository(StockItem::class)->query()->where('sku', 'A-1')->first();
        self::assertInstanceOf(StockItem::class, $loaded);
        $locked = static fn(): ?StockItem => $manager->repository(StockItem::class)->query()->where('sku', 'A-1')->lockForUpdate()->first();

        // Act: unchanged, the lock returns the same object
        $same = $this->connection->transaction($locked);
        $this->connection->table('stock_items')->where('sku', 'A-1')->update(['on_hand' => '4']);

        // Assert
        self::assertSame($loaded, $same);

        try {
            $this->connection->transaction($locked);
            self::fail('Expected StaleEntity: the in-memory item says 5 on hand, the row says 4.');
        } catch (StaleEntity $e) {
            self::assertStringContainsString('loaded before it was locked', $e->getMessage());
        }
    }

    private function manager(string $mapper): EntityManager
    {
        return new EntityManager($this->connection, $this->registry($mapper));
    }

    private function registry(string $mapper): MappingRegistry
    {
        if ($mapper === 'development') {
            return new DevelopmentRegistry([StockItemMap::class]);
        }

        $file = $this->directory . '/orm.php';

        if (!is_file($file)) {
            file_put_contents($file, new OrmCodeGenerator()->generate(new MetadataFactory()->build([new StockItemMap()])));
        }

        return OrmArtifact::load($file);
    }
}
