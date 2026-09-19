<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Orm;

use LogicException;
use PHPUnit\Framework\TestCase;
use Trunk\Orm\Exception\OrmException;
use Trunk\Orm\Exception\RelationNotLoaded;
use Trunk\Orm\UnitOfWork\EntityManager;
use Trunk\Tests\Fixtures\Orm\Customer;
use Trunk\Tests\Fixtures\Orm\Order;
use Trunk\Tests\Fixtures\Orm\Profile;
use Trunk\Tests\Fixtures\Orm\Status;
use Trunk\Tests\Fixtures\Orm\Tag;
use Trunk\Tests\Fixtures\Orm\TotalScope;
use Trunk\Tests\Support\OrmHarness;

final class QueryAndRelationsTest extends TestCase
{
    private OrmHarness $orm;

    private EntityManager $manager;

    protected function setUp(): void
    {
        $this->orm = new OrmHarness();
        $this->manager = $this->orm->manager();

        $names = ['Ada', 'Grace', 'Linus', 'Ken'];

        foreach ($names as $i => $name) {
            $this->manager->persist(new Customer(name: $name, email: strtolower($name) . '@example.com', status: $i === 3 ? Status::Blocked : Status::Active, balance: (float) ($i * 10)));
        }

        $this->manager->flush();

        foreach ([1 => 3, 2 => 2, 3 => 0, 4 => 1] as $customer => $orders) {
            for ($n = 1; $n <= $orders; ++$n) {
                $this->manager->persist(new Order(customerId: $customer, total: $n * 5.0));
            }
        }

        $this->manager->persist(new Profile(customerId: 1, bio: 'Analyst'));
        $this->manager->persist(new Tag(label: 'rush'));
        $this->manager->persist(new Tag(label: 'gift'));
        $this->manager->flush();
        $this->orm->connection->execute('INSERT INTO order_tag (order_id, tag_id) VALUES (1, 1), (1, 2), (2, 2)');
        $this->manager->clear();
        $this->orm->log->clear();
    }

    public function test_conditions_use_property_names_and_convert_values(): void
    {
        // Arrange
        $customers = $this->customers();

        // Act
        $blocked = $customers->query()->where('status', Status::Blocked)->get();
        $rich = $customers->query()->where('balance', '>=', 20)->orderBy('balance', 'desc')->get();
        $names = $customers->query()->whereIn('name', ['Ada', 'Ken'])->orderBy('name')->get();
        $union = $customers->query()->where('name', 'Ada')->orWhere('name', 'Ken')->count();

        // Assert
        self::assertSame(['Ken'], array_map(static fn(Customer $c): string => $c->name, $blocked));
        self::assertSame(['Ken', 'Linus'], array_map(static fn(Customer $c): string => $c->name, $rich));
        self::assertSame(['Ada', 'Ken'], array_map(static fn(Customer $c): string => $c->name, $names));
        self::assertSame(2, $union);
    }

    public function test_pagination_count_exists_and_the_keyset_cursor(): void
    {
        // Arrange
        $query = $this->customers()->query();

        // Act
        $page = $query->orderBy('name')->paginate(2, 3);
        $streamed = [];
        foreach ($query->cursor(2) as $customer) {
            $streamed[] = $customer->name;
        }

        // Assert
        self::assertSame(4, $page->total);
        self::assertSame(['Linus'], array_map(static fn(Customer $c): string => $c->name, $page->items));
        self::assertSame(2, $page->lastPage());
        self::assertSame(['Ada', 'Grace', 'Linus', 'Ken'], $streamed);
        self::assertTrue($query->where('name', 'Ada')->exists());
        self::assertFalse($query->where('name', 'Nobody')->exists());
    }

    public function test_read_only_queries_are_untracked(): void
    {
        // Arrange
        $customers = $this->customers();

        // Act
        $first = $customers->query()->readOnly()->where('name', 'Ada')->first() ?? throw new LogicException('missing');
        $second = $customers->query()->readOnly()->where('name', 'Ada')->first();
        $first->name = 'Changed';
        $this->manager->flush();

        // Assert
        self::assertNotSame($first, $second);
        self::assertSame('Ada', $this->orm->connection->table('customers')->where('id', 1)->value('name'));
    }

    public function test_relations_are_loaded_explicitly_with_one_query_each(): void
    {
        // Arrange
        $customers = $this->customers();

        // Act
        $all = $customers->query()->with('orders', 'profile')->orderBy('name')->get();
        $selects = array_filter($this->orm->log->entries(), static fn(array $e): bool => str_starts_with($e['sql'], 'SELECT'));
        $counts = array_map(fn(Customer $c): int => \count($this->manager->relatedMany($c, 'orders')), $all);

        // Assert
        self::assertCount(3, $selects, 'customers + orders + profile, regardless of how many customers.');
        self::assertSame([3, 2, 1, 0], $counts);
        self::assertSame('Analyst', ($profile = $this->manager->relatedOne($all[0], 'profile')) instanceof Profile ? $profile->bio : null);
        self::assertNull($this->manager->relatedOne($all[1], 'profile'));
    }

    public function test_touching_an_unloaded_relation_throws_instead_of_querying(): void
    {
        // Arrange
        $ada = $this->customers()->findOrFail(1);
        $this->orm->log->clear();

        // Act & Assert
        try {
            $this->manager->related($ada, 'orders');
            self::fail('Expected RelationNotLoaded.');
        } catch (RelationNotLoaded $e) {
            self::assertStringContainsString("->with('orders')", $e->getMessage());
            self::assertSame(0, $this->orm->log->count());
        }
    }

    public function test_belongs_to_and_belongs_to_many_and_nested_paths(): void
    {
        // Arrange
        $orders = $this->manager->repository(Order::class);

        // Act
        $loaded = $orders->query()->with('customer', 'tags')->orderBy('id')->limit(3)->get();
        $nested = $this->customers()->query()->with('orders.tags')->where('name', 'Ada')->first();

        // Assert
        $owner = $this->manager->relatedOne($loaded[0], 'customer');
        self::assertInstanceOf(Customer::class, $owner);
        self::assertSame('Ada', $owner->name);
        self::assertSame(['gift', 'rush'], $this->labels($this->manager->relatedMany($loaded[0], 'tags')));
        self::assertSame(['gift'], $this->labels($this->manager->relatedMany($loaded[1], 'tags')));
        self::assertSame([], $this->manager->relatedMany($loaded[2], 'tags'));
        self::assertNotNull($nested);
        $adaOrders = $this->manager->relatedMany($nested, 'orders');
        self::assertCount(3, $adaOrders);
        self::assertSame(['gift', 'rush'], $this->labels($this->manager->relatedMany($adaOrders[0], 'tags')));
    }

    public function test_eager_loading_many_parents_stays_at_a_fixed_number_of_queries(): void
    {
        // Arrange
        for ($i = 0; $i < 600; ++$i) {
            $this->manager->persist(new Customer(name: 'Bulk' . $i, email: 'bulk' . $i . '@example.com'));
        }

        $this->manager->flush();
        $this->manager->clear();
        $this->orm->log->clear();

        // Act
        $this->customers()->query()->with('orders')->get();
        $selects = array_filter($this->orm->log->entries(), static fn(array $e): bool => str_starts_with($e['sql'], 'SELECT'));

        // Assert
        self::assertCount(3, $selects, '1 for customers + 2 chunks of 500 keys for orders.');
    }

    public function test_the_n_plus_one_detector_names_the_repeated_query(): void
    {
        // Arrange
        $orders = $this->manager->repository(Order::class);
        $customers = $this->customers();

        // Act
        foreach ($orders->query()->get() as $order) {
            $this->manager->clear();
            $customers->query()->where('id', $order->customerId)->first();
        }

        $findings = $this->manager->nPlusOneFindings(3);

        // Assert
        self::assertNotEmpty($findings);
        self::assertGreaterThanOrEqual(3, $findings[0]->count);
        self::assertStringContainsString('->with(', $findings[0]->message());
        self::assertSame([], $this->manager->nPlusOneFindings(1000));
    }

    public function test_global_scopes_apply_everywhere_including_related_rows_and_fail_closed_when_missing(): void
    {
        // Arrange
        $scope = new TotalScope();
        $orm = new OrmHarness(new \Trunk\Orm\Mapping\DevelopmentRegistry([\Trunk\Tests\Fixtures\Orm\CustomerMap::class, \Trunk\Tests\Fixtures\Orm\ScopedOrderMap::class, \Trunk\Tests\Fixtures\Orm\ProfileMap::class, \Trunk\Tests\Fixtures\Orm\TagMap::class]));
        $orm->connection->execute('INSERT INTO orders (customer_id, total) VALUES (1, 5), (1, 10), (1, 15)');
        $orm->connection->execute("INSERT INTO customers (name, email, password_hash, status, settings, created_at) VALUES ('A', 'a@x', 'h', 'active', '[]', '2026-01-01 00:00:00')");

        // Act
        $scoped = $orm->manager([$scope]);
        $direct = $scoped->repository(Order::class)->query()->count();
        $viaRelation = \count($scoped->relatedMany($scoped->repository(Customer::class)->query()->with('orders')->first() ?? new Customer(), 'orders'));
        $unscoped = $scoped->repository(Order::class)->query()->withoutScope($scope::class)->count();

        // Assert
        self::assertSame(2, $direct);
        self::assertSame(2, $viaRelation);
        self::assertSame(3, $unscoped);
        $this->expectException(OrmException::class);
        $orm->manager()->repository(Order::class)->query()->count();
    }

    public function test_or_where_cannot_escape_soft_delete_or_scopes(): void
    {
        // Arrange
        $ken = $this->customers()->findOrFail(4);
        $this->manager->remove($ken);
        $this->manager->flush();

        // Act
        $found = $this->customers()->query()->where('name', 'Ken')->orWhere('name', 'Ada')->get();

        // Assert
        self::assertSame(['Ada'], array_map(static fn(Customer $c): string => $c->name, $found));
        $sql = array_values(array_filter($this->orm->log->entries(), static fn(array $e): bool => str_contains($e['sql'], '"name" = ?')));
        self::assertStringContainsString('("name" = ? OR "name" = ?) AND "deleted_at" IS NULL', $sql[\count($sql) - 1]['sql']);
    }

    /**
     * @return \Trunk\Orm\Repository\Repository<Customer>
     */
    private function customers(): \Trunk\Orm\Repository\Repository
    {
        return $this->manager->repository(Customer::class);
    }

    /**
     * @param list<object> $tags
     *
     * @return list<string>
     */
    private function labels(array $tags): array
    {
        $labels = array_map(static fn(object $t): string => $t instanceof Tag ? $t->label : '', $tags);
        sort($labels);

        return $labels;
    }
}
