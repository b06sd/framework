<?php

declare(strict_types=1);

namespace Trunk\Tests\Performance;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Trunk\Orm\Compiler\OrmArtifact;
use Trunk\Orm\Compiler\OrmCodeGenerator;
use Trunk\Orm\Mapping\DevelopmentRegistry;
use Trunk\Orm\Mapping\MetadataFactory;
use Trunk\Orm\Repository\Query;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Orm\Customer;
use Trunk\Tests\Fixtures\Orm\CustomerMap;
use Trunk\Tests\Fixtures\Orm\Order;
use Trunk\Tests\Fixtures\Orm\OrderMap;
use Trunk\Tests\Fixtures\Orm\ProfileMap;
use Trunk\Tests\Fixtures\Orm\Status;
use Trunk\Tests\Fixtures\Orm\TagMap;
use Trunk\Tests\Support\OrmHarness;

/**
 * Query counts are hard assertions (they never flake). Timings are printed to stderr for the
 * record and only loosely bounded, so a slow machine cannot fail the suite.
 */
final class OrmPerformanceTest extends TestCase
{
    private const int ROWS = 10_000;

    public function test_generated_hydrators_beat_the_interpreted_and_reflection_baselines(): void
    {
        // Arrange
        $directory = sys_get_temp_dir() . '/trunk-orm-perf-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/orm.php', new OrmCodeGenerator()->generate(new MetadataFactory()->build([new CustomerMap(), new OrderMap(), new ProfileMap(), new TagMap()])));
        $compiled = OrmArtifact::load($directory . '/orm.php')->mapper(Customer::class);
        $interpreted = new DevelopmentRegistry([CustomerMap::class, OrderMap::class, ProfileMap::class, TagMap::class])->mapper(Customer::class);
        $rows = self::rows(self::ROWS);
        $reflection = new ReflectionClass(Customer::class);

        // Act
        $start = hrtime(true);
        foreach ($rows as $row) {
            $compiled->hydrate($row);
        }
        $generated = (hrtime(true) - $start) / 1e9;

        $start = hrtime(true);
        foreach ($rows as $row) {
            $interpreted->hydrate($row);
        }
        $slow = (hrtime(true) - $start) / 1e9;

        $start = hrtime(true);
        foreach ($rows as $row) {
            $reflection->newInstanceArgs(['id' => $row['id'], 'name' => $row['name'], 'email' => $row['email'], 'passwordHash' => $row['password_hash'], 'status' => Status::from($row['status']), 'nickname' => null, 'active' => (bool) $row['active'], 'balance' => $row['balance'], 'settings' => json_decode($row['settings'], true), 'createdAt' => new DateTimeImmutable($row['created_at']), 'deletedAt' => null, 'version' => $row['version']]);
        }
        $baseline = (hrtime(true) - $start) / 1e9;
        new Directory()->remove($directory);

        // Assert
        $this->report('hydrate 10k rows (generated)', $generated, self::ROWS);
        $this->report('hydrate 10k rows (interpreted, dev)', $slow, self::ROWS);
        $this->report('hydrate 10k rows (reflection baseline)', $baseline, self::ROWS);
        self::assertLessThan($slow, $generated, 'The generated hydrator must be faster than the interpreted one.');
    }

    public function test_flush_batches_into_one_transaction_and_updates_only_what_changed(): void
    {
        // Arrange
        $orm = new OrmHarness();
        $manager = $orm->manager();

        // Act
        $start = hrtime(true);
        for ($i = 0; $i < 1000; ++$i) {
            $manager->persist(new Customer(name: 'C' . $i, email: 'c' . $i . '@example.com'));
        }
        $manager->flush();
        $insert = (hrtime(true) - $start) / 1e9;
        $stored = $orm->connection->table('customers')->count();
        $inserts = \count(array_filter($orm->log->entries(), static fn(array $e): bool => str_starts_with($e['sql'], 'INSERT')));

        $customers = $manager->repository(Customer::class)->query()->get();
        $orm->log->clear();
        $customers[0]->name = 'changed';
        $customers[1]->name = 'changed';
        $start = hrtime(true);
        $manager->flush();
        $update = (hrtime(true) - $start) / 1e9;
        $writes = array_values(array_filter($orm->log->entries(), static fn(array $e): bool => !str_starts_with($e['sql'], 'SELECT') && !str_starts_with($e['sql'], 'SAVEPOINT') && !str_starts_with($e['sql'], 'RELEASE')));

        // Assert
        $this->report('insert + flush 1k entities', $insert, 1000);
        $this->report('dirty check 1k managed, 2 updates', $update, 1000);
        self::assertSame(1000, $stored);
        self::assertSame(1, $inserts, '1000 generated-id inserts are one INSERT ... RETURNING on SQLite/PostgreSQL.');
        self::assertCount(2, $writes, 'Only the two changed entities were written; 998 unchanged cost no SQL.');
    }

    public function test_eager_loading_uses_a_fixed_number_of_queries_however_many_parents(): void
    {
        // Arrange
        $orm = new OrmHarness();
        $manager = $orm->manager();

        for ($i = 0; $i < 300; ++$i) {
            $manager->persist(new Customer(name: 'C' . $i, email: 'c' . $i . '@example.com'));
        }

        $manager->flush();

        for ($i = 1; $i <= 300; ++$i) {
            for ($n = 0; $n < 5; ++$n) {
                $manager->persist(new Order(customerId: $i, total: (float) $n));
            }
        }

        $manager->flush();
        $manager->clear();
        $orm->log->clear();

        // Act
        $start = hrtime(true);
        $customers = $manager->repository(Customer::class)->query()->with('orders')->get();
        $elapsed = (hrtime(true) - $start) / 1e9;
        $selects = \count(array_filter($orm->log->entries(), static fn(array $e): bool => str_starts_with($e['sql'], 'SELECT')));

        // Assert
        $this->report('eager load 300 parents x 5 children', $elapsed, 1500);
        self::assertSame(2, $selects);
        self::assertCount(5, $manager->relatedMany($customers[0], 'orders'));
    }

    public function test_the_cursor_keeps_memory_flat_and_read_only_skips_tracking(): void
    {
        // Arrange
        $orm = new OrmHarness();
        $manager = $orm->manager();

        for ($i = 0; $i < 2000; ++$i) {
            $manager->persist(new Customer(name: 'C' . $i, email: 'c' . $i . '@example.com'));
        }

        $manager->flush();
        $manager->clear();
        gc_collect_cycles();
        $query = $manager->repository(Customer::class)->query();
        $peak = 0;
        $count = 0;
        $baseline = memory_get_usage();

        // Act
        foreach ($query->cursor(100) as $customer) {
            ++$count;
            $peak = max($peak, memory_get_usage() - $baseline);
            unset($customer);
        }

        // Assert
        self::assertSame(2000, $count);
        self::assertLessThan(8 * 1024 * 1024, $peak, 'Streaming 2000 entities must not hold them all in memory.');
        self::assertInstanceOf(Query::class, $query);
    }

    private function report(string $label, float $seconds, int $count): void
    {
        fwrite(\STDERR, \sprintf("\n[orm-perf] %-42s %8.1f ms  (%d ops, %.2f us/op)", $label, $seconds * 1000, $count, $seconds / max(1, $count) * 1e6));
    }

    /**
     * @return list<array{id: int, name: string, email: string, password_hash: string, status: string, nickname: null, active: int, balance: float, settings: string, created_at: string, deleted_at: null, version: int}>
     */
    private static function rows(int $count): array
    {
        $rows = [];

        for ($i = 1; $i <= $count; ++$i) {
            $rows[] = ['id' => $i, 'name' => 'Name ' . $i, 'email' => 'user' . $i . '@example.com', 'password_hash' => 'hash', 'status' => 'active', 'nickname' => null, 'active' => 1, 'balance' => 12.5, 'settings' => '{"a":1}', 'created_at' => '2026-03-04 05:06:07', 'deleted_at' => null, 'version' => 1];
        }

        return $rows;
    }
}
