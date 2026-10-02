<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Orm;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Trunk\Database\Schema\Blueprint;
use Trunk\Database\Schema\Schema;
use Trunk\Orm\Exception\OrmException;
use Trunk\Orm\UnitOfWork\Change;
use Trunk\Orm\UnitOfWork\ChangeKind;
use Trunk\Orm\UnitOfWork\ChangeListener;
use Trunk\Orm\UnitOfWork\Changes;
use Trunk\Orm\UnitOfWork\EntityManager;
use Trunk\Tests\Fixtures\Orm\AuditingListener;
use Trunk\Tests\Fixtures\Orm\Customer;
use Trunk\Tests\Fixtures\Orm\Order;
use Trunk\Tests\Fixtures\Orm\Status;
use Trunk\Tests\Support\OrmHarness;
use Trunk\Tests\Support\RecordingChangeListener;

/**
 * ChangeListener: what a flush wrote, by property and in stored form, delivered inside the flush's
 * transaction, so a listener's own writes (an audit row) commit or roll back with the changes.
 */
final class ChangeListenerTest extends TestCase
{
    public function test_inserts_updates_and_deletes_are_reported_by_property_in_stored_form(): void
    {
        // Arrange
        $harness = new OrmHarness();
        $recorder = new RecordingChangeListener();
        $manager = $this->manager($harness, $recorder);
        $ada = new Customer(name: 'Ada', email: 'ada@example.com', passwordHash: 'secret-hash', status: Status::Blocked, settings: ['theme' => 'dark']);
        $manager->persist($ada);
        $manager->flush();
        $inserted = $recorder->batches[0];

        // Act: change two properties (one of them hidden), then soft-delete
        $ada->name = 'Ada Lovelace';
        $ada->passwordHash = 'new-hash';
        $manager->flush();
        $updated = $recorder->batches[1];
        $manager->remove($ada);
        $manager->flush();
        $deleted = $recorder->batches[2];

        // Assert: the insert, with every property and its stored form; the hidden one masked
        $insert = $inserted->all()[0];
        self::assertSame([ChangeKind::Insert, Customer::class, $ada, $ada->id, []], [$insert->kind, $insert->class, $insert->entity, $insert->id, $insert->before]);
        self::assertSame('Ada', $insert->after['name']);
        self::assertSame('blocked', $insert->after['status'], 'an enum as its stored value');
        self::assertSame('{"theme":"dark"}', $insert->after['settings']);
        self::assertSame('2026-01-01 00:00:00', $insert->after['createdAt']);
        self::assertSame(Change::HIDDEN, $insert->after['passwordHash']);
        self::assertArrayNotHasKey('version', $insert->after, 'the optimistic-lock version is bookkeeping, not data');

        // ...the update, with only what changed
        $update = $updated->all()[0];
        self::assertSame(ChangeKind::Update, $update->kind);
        self::assertSame(['name' => 'Ada', 'passwordHash' => Change::HIDDEN], $update->before);
        self::assertSame(['name' => 'Ada Lovelace', 'passwordHash' => Change::HIDDEN], $update->after);
        self::assertSame(['name', 'passwordHash'], $update->properties());

        // ...and the soft delete, with what the row held
        $delete = $deleted->all()[0];
        self::assertSame([ChangeKind::Delete, true, []], [$delete->kind, $delete->soft, $delete->after]);
        self::assertSame('Ada Lovelace', $delete->before['name']);
        $reported = json_encode(array_map(static fn(Changes $batch): array => array_map(static fn(Change $c): array => [$c->before, $c->after], $batch->all()), $recorder->batches), \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Ada Lovelace', $reported, 'the check below looks at real values');
        self::assertStringNotContainsString('secret-hash', $reported, 'no hidden value is ever reported');
        self::assertStringNotContainsString('new-hash', $reported);
    }

    public function test_one_flush_is_one_batch_in_write_order_and_of_filters_by_class(): void
    {
        // Arrange
        $harness = new OrmHarness();
        $recorder = new RecordingChangeListener();
        $manager = $this->manager($harness, $recorder);
        $customer = new Customer(name: 'Ada', email: 'ada@example.com');
        $manager->persist($customer);
        $manager->flush();

        // Act
        $manager->persist(new Order(customerId: (int) $customer->id, total: 12.5));
        $customer->nickname = 'countess';
        $manager->flush();
        $batch = $recorder->batches[1];

        // Assert
        self::assertCount(2, $batch);
        self::assertSame([ChangeKind::Insert, ChangeKind::Update], array_map(static fn(Change $c): ChangeKind => $c->kind, $batch->all()));
        self::assertCount(1, $batch->of(Order::class));
        self::assertSame(['nickname' => 'countess'], $batch->of(Customer::class)[0]->after);
    }

    public function test_a_listeners_writes_commit_with_the_changes_and_its_failure_rolls_everything_back(): void
    {
        // Arrange: an audit trail written by the project, through the connection
        $harness = new OrmHarness();
        new Schema($harness->connection)->create('audit', static function (Blueprint $t): void {
            $t->id();
            $t->string('entity');
            $t->string('action');
            $t->text('after');
        });
        $audit = new AuditingListener($harness->connection);
        $manager = $this->manager($harness, $audit);

        // Act: a flush that is audited, then one whose listener fails
        $manager->persist(new Customer(name: 'Ada', email: 'ada@example.com'));
        $manager->flush();
        $audit->fail = true;

        try {
            $failing = $this->manager($harness, $audit);
            $failing->persist(new Customer(name: 'Bob', email: 'bob@example.com'));
            $failing->flush();
            self::fail('The listener failure must reach the caller.');
        } catch (RuntimeException $e) {
            self::assertSame('audit store is down', $e->getMessage());
        }

        // Assert
        self::assertSame(['Ada'], $harness->connection->table('customers')->pluck('name'), 'the failed flush wrote nothing');
        self::assertSame(1, $harness->connection->table('audit')->count());
        $after = $harness->connection->table('audit')->value('after');
        self::assertIsString($after);
        self::assertStringContainsString('"name":"Ada"', $after);
    }

    public function test_flushing_from_inside_a_listener_is_refused_and_rolls_back(): void
    {
        // Arrange: a listener that (wrongly) saves through the same entity manager
        $harness = new OrmHarness();
        $listener = new class implements ChangeListener {
            public ?EntityManager $manager = null;

            public function changed(Changes $changes): void
            {
                $this->manager?->persist(new Customer(name: 'Shadow', email: 'shadow@example.com'));
                $this->manager?->flush();
            }
        };
        $manager = $this->manager($harness, $listener);
        $listener->manager = $manager;
        $manager->persist(new Customer(name: 'Ada', email: 'ada@example.com'));

        // Act & Assert
        try {
            $manager->flush();
            self::fail('Expected OrmException.');
        } catch (OrmException $e) {
            self::assertStringContainsString('flush() was called from a ChangeListener', $e->getMessage());
        }

        self::assertSame(0, $harness->connection->table('customers')->count());
    }

    private function manager(OrmHarness $harness, ChangeListener $listener): EntityManager
    {
        return new EntityManager($harness->connection, $harness->registry, [], [$listener]);
    }
}
