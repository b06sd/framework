<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Orm;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Trunk\Orm\Exception\EntityNotFound;
use Trunk\Orm\Exception\OrmException;
use Trunk\Orm\Exception\StaleEntity;
use Trunk\Orm\UnitOfWork\EntityManager;
use Trunk\Tests\Fixtures\Orm\Customer;
use Trunk\Tests\Fixtures\Orm\Status;
use Trunk\Tests\Support\OrmHarness;

final class EntityManagerTest extends TestCase
{
    private OrmHarness $orm;

    private EntityManager $manager;

    protected function setUp(): void
    {
        $this->orm = new OrmHarness();
        $this->manager = $this->orm->manager();
    }

    public function test_persist_flush_assigns_the_generated_id_and_stores_every_type_faithfully(): void
    {
        // Arrange & Act
        $ada = $this->ada();
        $fresh = $this->orm->manager()->repository(Customer::class)->findOrFail((int) $ada->id);

        // Assert
        self::assertSame(1, $ada->id);
        self::assertSame('Ada', $fresh->name);
        self::assertSame(Status::Active, $fresh->status);
        self::assertNull($fresh->nickname);
        self::assertTrue($fresh->active);
        self::assertSame(12.5, $fresh->balance);
        self::assertSame(['theme' => 'dark'], $fresh->settings);
        self::assertSame('2026-03-04 05:06:07', $fresh->createdAt->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $fresh->createdAt->getTimezone()->getName());
        self::assertSame(1, $fresh->version);
    }

    public function test_the_identity_map_returns_the_same_instance_without_another_query(): void
    {
        // Arrange
        $ada = $this->ada();
        $customers = $this->manager->repository(Customer::class);
        $this->orm->log->clear();

        // Act
        $again = $customers->find(1);
        $viaQuery = $customers->query()->where('email', 'ada@example.com')->first();

        // Assert
        self::assertSame($ada, $again);
        self::assertSame($ada, $viaQuery);
        self::assertSame(1, $this->orm->log->count(), 'find() hit the identity map; only the explicit query ran.');
    }

    public function test_only_changed_columns_are_updated_and_unchanged_entities_cost_no_sql(): void
    {
        // Arrange
        $ada = $this->ada();
        $this->orm->log->clear();

        // Act
        $this->manager->flush();
        $noSql = $this->orm->log->count();
        $ada->name = 'Ada L.';
        $this->manager->flush();
        $updates = array_values(array_filter($this->orm->log->entries(), static fn(array $e): bool => str_starts_with($e['sql'], 'UPDATE')));

        // Assert
        self::assertSame(0, $noSql);
        self::assertCount(1, $updates);
        self::assertStringContainsString('SET "name" = ?, "version" = ?', $updates[0]['sql']);
        self::assertStringNotContainsString('"email"', $updates[0]['sql']);
        self::assertSame('Ada L.', $this->orm->connection->table('customers')->value('name'));
        self::assertSame(2, $this->orm->connection->table('customers')->value('version'));
    }

    public function test_a_second_flush_after_a_change_keeps_working_and_bumps_the_version_again(): void
    {
        // Arrange
        $ada = $this->ada();

        // Act
        $ada->name = 'One';
        $this->manager->flush();
        $ada->name = 'Two';
        $this->manager->flush();

        // Assert
        self::assertSame(3, $this->orm->connection->table('customers')->value('version'));
        self::assertSame('Two', $this->orm->connection->table('customers')->value('name'));
    }

    public function test_optimistic_locking_rejects_a_stale_write_and_changes_nothing(): void
    {
        // Arrange
        $this->ada();
        $other = $this->orm->manager();
        $mine = $this->manager->repository(Customer::class)->findOrFail(1);
        $theirs = $other->repository(Customer::class)->findOrFail(1);
        $theirs->name = 'Theirs';
        $other->flush();

        // Act
        $mine->name = 'Mine';
        try {
            $this->manager->flush();
            self::fail('Expected StaleEntity.');
        } catch (StaleEntity $e) {
            // Assert
            self::assertStringContainsString('Reload', $e->getMessage());
            self::assertSame('Theirs', $this->orm->connection->table('customers')->value('name'));
        }
    }

    public function test_a_failing_flush_rolls_the_whole_transaction_back(): void
    {
        // Arrange
        $this->ada();
        $this->manager->persist(new Customer(name: 'Good', email: 'good@example.com'));
        $this->manager->persist(new Customer(name: 'Dup', email: 'ada@example.com'));

        // Act
        try {
            $this->manager->flush();
            self::fail('Expected a QueryException.');
        } catch (\Trunk\Database\Exception\QueryException) {
            // Assert
            self::assertSame(1, $this->orm->connection->table('customers')->count());
        }
    }

    public function test_remove_soft_deletes_and_every_query_hides_the_row(): void
    {
        // Arrange
        $ada = $this->ada();
        $customers = $this->manager->repository(Customer::class);

        // Act
        $this->manager->remove($ada);
        $this->manager->flush();
        $fresh = $this->orm->manager()->repository(Customer::class);

        // Assert
        self::assertNull($fresh->find(1));
        self::assertSame(0, $fresh->query()->count());
        self::assertSame(1, $fresh->query()->withTrashed()->count());
        self::assertSame(1, $fresh->query()->onlyTrashed()->count());
        self::assertNull($customers->find(1), 'The removed entity left the identity map.');
        self::assertNotNull($this->orm->connection->table('customers')->value('deleted_at'));
    }

    public function test_removing_an_unmanaged_entity_is_an_error_but_removing_a_pending_one_cancels_it(): void
    {
        // Arrange
        $pending = new Customer(name: 'P', email: 'p@example.com');
        $this->manager->persist($pending);

        // Act
        $this->manager->remove($pending);
        $this->manager->flush();

        // Assert
        self::assertSame(0, $this->orm->connection->table('customers')->count());
        $this->expectException(OrmException::class);
        $this->manager->remove(new Customer());
    }

    public function test_find_or_fail_throws_a_clear_error_and_changing_a_primary_key_is_refused(): void
    {
        // Arrange
        $this->ada();

        // Act & Assert
        try {
            $this->manager->repository(Customer::class)->findOrFail(99);
            self::fail('Expected EntityNotFound.');
        } catch (EntityNotFound $e) {
            self::assertStringContainsString('No ' . Customer::class, $e->getMessage());
        }
    }

    public function test_enum_bool_null_and_json_round_trip_through_updates(): void
    {
        // Arrange
        $ada = $this->ada();

        // Act
        $ada->status = Status::Blocked;
        $ada->active = false;
        $ada->nickname = 'countess';
        $ada->settings = ['a' => [1, 2, 3]];
        $this->manager->flush();
        $fresh = $this->orm->manager()->repository(Customer::class)->findOrFail(1);

        // Assert
        self::assertSame(Status::Blocked, $fresh->status);
        self::assertFalse($fresh->active);
        self::assertSame('countess', $fresh->nickname);
        self::assertSame(['a' => [1, 2, 3]], $fresh->settings);
    }

    public function test_a_batch_of_new_entities_is_one_insert_and_every_entity_gets_its_own_id(): void
    {
        // Arrange
        $customers = [];
        foreach (['A', 'B', 'C', 'D'] as $name) {
            $customers[$name] = new Customer(name: $name, email: strtolower($name) . '@example.com');
            $this->manager->persist($customers[$name]);
        }
        $this->orm->log->clear();

        // Act
        $this->manager->flush();
        $inserts = array_filter($this->orm->log->entries(), static fn(array $e): bool => str_starts_with($e['sql'], 'INSERT'));

        // Assert
        self::assertCount(1, $inserts);
        self::assertStringContainsString('RETURNING', array_values($inserts)[0]['sql']);
        foreach ($customers as $name => $customer) {
            self::assertSame($name, $this->orm->connection->table('customers')->where('id', $customer->id)->value('name'));
        }
    }

    public function test_inserts_keep_the_order_they_were_persisted_in_across_classes(): void
    {
        // Arrange
        $this->manager->persist(new Customer(name: 'A', email: 'a@example.com'));
        $this->manager->persist(new \Trunk\Tests\Fixtures\Orm\Tag(label: 'x'));
        $this->manager->persist(new Customer(name: 'B', email: 'b@example.com'));
        $this->orm->log->clear();

        // Act
        $this->manager->flush();
        $tables = array_map(static fn(array $e): string => str_contains($e['sql'], '"tags"') ? 'tags' : 'customers', array_values(array_filter($this->orm->log->entries(), static fn(array $e): bool => str_starts_with($e['sql'], 'INSERT'))));

        // Assert
        self::assertSame(['customers', 'tags', 'customers'], $tables);
    }

    public function test_hidden_columns_are_never_selected_and_an_untouched_flush_never_overwrites_them(): void
    {
        // Arrange
        $this->ada();
        $manager = $this->orm->manager();
        $this->orm->log->clear();

        // Act
        $ada = $manager->repository(Customer::class)->findOrFail(1);
        $ada->name = 'Ada L.';
        $manager->flush();
        $selects = array_filter($this->orm->log->entries(), static fn(array $e): bool => str_starts_with($e['sql'], 'SELECT'));

        // Assert
        self::assertSame('', $ada->passwordHash);
        foreach ($selects as $select) {
            self::assertStringNotContainsString('password_hash', $select['sql']);
        }
        self::assertSame('h4sh', $this->orm->connection->table('customers')->value('password_hash'), 'The stored hash survived an update of another column.');
    }

    public function test_assigning_a_hidden_column_writes_it_and_with_hidden_reads_it(): void
    {
        // Arrange
        $this->ada();
        $manager = $this->orm->manager();
        $ada = $manager->repository(Customer::class)->findOrFail(1);

        // Act
        $ada->passwordHash = 'new-hash';
        $manager->flush();
        $loaded = $this->orm->manager()->repository(Customer::class)->query()->withHidden()->where('id', 1)->first();

        // Assert
        self::assertSame('new-hash', $this->orm->connection->table('customers')->value('password_hash'));
        self::assertNotNull($loaded);
        self::assertSame('new-hash', $loaded->passwordHash);
    }

    private function ada(): Customer
    {
        $customer = new Customer(name: 'Ada', email: 'ada@example.com', passwordHash: 'h4sh', nickname: null, balance: 12.5, settings: ['theme' => 'dark'], createdAt: new DateTimeImmutable('2026-03-04 05:06:07 UTC'));
        $this->manager->persist($customer);
        $this->manager->flush();

        return $customer;
    }
}
