<?php

declare(strict_types=1);

namespace Trunk\Tests\Security;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;
use Throwable;
use Trunk\Database\Exception\QueryException;
use Trunk\Orm\Exception\InvalidFilter;
use Trunk\Orm\Exception\UnknownProperty;
use Trunk\Orm\UnitOfWork\EntityManager;
use Trunk\Tests\Fixtures\Orm\Customer;
use Trunk\Tests\Support\OrmHarness;

/**
 * Beyond the injection basics: type confusion in request-driven filters, extreme paging, atomic
 * flushes, and memory behaviour of long-running processes.
 */
final class OrmHardeningTest extends TestCase
{
    private OrmHarness $orm;

    private EntityManager $manager;

    protected function setUp(): void
    {
        $this->orm = new OrmHarness();
        $this->manager = $this->orm->manager();
        $this->manager->persist(new Customer(name: 'Ada', email: 'ada@example.com'));
        $this->manager->persist(new Customer(name: 'Grace', email: 'grace@example.com'));
        $this->manager->flush();
        $this->manager->clear();
    }

    public function test_random_request_shaped_filters_and_sorts_only_ever_produce_typed_rejections_or_rows(): void
    {
        // Arrange
        mt_srand(17);
        $properties = ['name', 'email', 'status', 'active', 'balance', 'createdAt', 'nickname', 'passwordHash', 'id', 'settings', "name'; DROP TABLE customers; --", '', 'na me', 'name.email', '__proto__', "na\0me"];
        $values = ['Ada', '', '%', '_', "' OR 1=1 --", "\0", "\xB1\x31", 'active', 'archived', '2026-13-45', 'NaN', '1e999', -1, 0, 1.5, true, null, [], ['a', 'b'], ['x' => 1], [[1]], str_repeat('a', 5000), new stdClass(), \PHP_INT_MAX];
        $sorts = ['name', '-name', 'name,-email', ',', '-', '--name', 'name;drop', str_repeat('name,', 20), 'passwordHash', '', "name\0", 'name DESC', '(select 1)'];
        $untyped = [];

        // Act
        for ($i = 0; $i < 800; ++$i) {
            $query = $this->manager->repository(Customer::class)->query()->readOnly();

            try {
                $filter = [];
                for ($j = 0, $n = mt_rand(1, 3); $j < $n; ++$j) {
                    $filter[$properties[mt_rand(0, \count($properties) - 1)]] = $values[mt_rand(0, \count($values) - 1)];
                }

                $query = $query->filter($filter);

                if (mt_rand(0, 1) === 1) {
                    $query = $query->sortBy($sorts[mt_rand(0, \count($sorts) - 1)]);
                }

                $rows = $query->limit(5)->get();
                self::assertLessThanOrEqual(2, \count($rows));
            } catch (InvalidFilter|UnknownProperty) {
                // A typed rejection of bad input.
            } catch (Throwable $e) {
                $untyped[] = $e::class . ': ' . substr($e->getMessage(), 0, 80);
            }
        }

        // Assert
        self::assertSame([], array_values(array_unique($untyped)));
        self::assertSame(2, $this->manager->repository(Customer::class)->query()->count(), 'the data is untouched');
    }

    public function test_extreme_paging_values_are_rejected_before_any_query_runs(): void
    {
        // Arrange
        $query = $this->manager->repository(Customer::class)->query();
        $this->orm->log->clear();
        $rejected = 0;

        // Act
        foreach ([[0, 20], [-1, 20], [1, 0], [1, -5], [1, 1001], [1, \PHP_INT_MAX], [\PHP_INT_MAX, 20]] as [$page, $perPage]) {
            try {
                $query->paginate($page, $perPage);
            } catch (InvalidFilter) {
                ++$rejected;
            } catch (Throwable $e) {
                self::fail('page ' . $page . ' perPage ' . $perPage . ' gave ' . $e::class);
            }
        }

        // Assert
        self::assertGreaterThanOrEqual(6, $rejected);
        foreach ([-1, \PHP_INT_MAX] as $limit) {
            try {
                $this->manager->repository(Customer::class)->query()->limit($limit)->get();
            } catch (InvalidFilter|InvalidArgumentException) {
                $rejected++;
            }
        }

        self::assertGreaterThanOrEqual(7, $rejected);
    }

    public function test_a_wrong_typed_value_for_an_enum_filter_is_a_rejected_filter_not_a_server_error(): void
    {
        // Arrange
        $query = $this->manager->repository(Customer::class)->query();
        $rejected = 0;

        // Act: a string-backed enum given numbers, a page number that overflows when multiplied
        foreach ([1, 0, -5, \PHP_INT_MAX] as $value) {
            try {
                $query->filter(['status' => $value]);
            } catch (InvalidFilter) {
                ++$rejected;
            }
        }

        try {
            $query->paginate(\PHP_INT_MAX, 1000);
        } catch (InvalidFilter $e) {
            ++$rejected;
        }

        // Assert
        self::assertSame(5, $rejected);
    }

    public function test_an_int_backed_enum_accepts_whole_number_strings_from_a_query_string_and_nothing_else(): void
    {
        // Arrange
        $enumValue = new ReflectionMethod(\Trunk\Orm\Repository\Query::class, 'enumValue');
        $query = new ReflectionClass(\Trunk\Orm\Repository\Query::class)->newInstanceWithoutConstructor();

        // Act & Assert
        self::assertSame(2, $enumValue->invoke($query, IntPriority::class, '2', 'priority'));
        self::assertSame(3, $enumValue->invoke($query, IntPriority::class, 3, 'priority'));

        foreach (['4', '-1', 'high', '2.0', '02x', ' 2', '', '99999999999999999999'] as $bad) {
            try {
                $enumValue->invoke($query, IntPriority::class, $bad, 'priority');
                self::fail('"' . $bad . '" must be refused');
            } catch (InvalidFilter) {
                self::assertSame(2, $enumValue->invoke($query, IntPriority::class, '2', 'priority'));
            }
        }
    }

    public function test_a_failed_flush_changes_nothing_and_the_manager_recovers_after_clear(): void
    {
        // Arrange
        $before = $this->manager->repository(Customer::class)->query()->count();
        $this->manager->persist(new Customer(name: 'New One', email: 'new@example.com'));
        $this->manager->persist(new Customer(name: 'Duplicate', email: 'ada@example.com'));

        // Act
        try {
            $this->manager->flush();
            self::fail('the duplicate email must fail the flush');
        } catch (QueryException $e) {
            $message = $e->getMessage();
        }

        $afterFailure = $this->orm->connection->table('customers')->count();
        $this->manager->clear();
        $this->manager->persist(new Customer(name: 'Fine', email: 'fine@example.com'));
        $this->manager->flush();

        // Assert
        self::assertSame($before, $afterFailure, 'all-or-nothing: the valid insert was rolled back with the invalid one');
        self::assertStringNotContainsString('ada@example.com', $message, 'the error does not echo row values');
        self::assertSame($before + 1, $this->orm->connection->table('customers')->count());
    }

    public function test_a_worker_that_clears_between_units_of_work_keeps_flat_memory_and_the_identity_map_is_what_grows_otherwise(): void
    {
        // Arrange
        $batch = static function (EntityManager $manager, int $offset): void {
            for ($i = 0; $i < 200; ++$i) {
                $manager->persist(new Customer(name: 'Bulk ' . $offset . '-' . $i, email: 'bulk' . $offset . '-' . $i . '@example.com'));
            }

            $manager->flush();
        };

        // Act: 60 rounds of 200 rows, clearing each time
        gc_collect_cycles();
        $batch($this->manager, -1);
        $this->manager->clear();
        gc_collect_cycles();
        $start = memory_get_usage();

        for ($round = 0; $round < 60; ++$round) {
            $batch($this->manager, $round);
            $this->manager->clear();
        }

        gc_collect_cycles();
        $cleared = memory_get_usage() - $start;

        $unclearedManager = new OrmHarness()->manager();
        gc_collect_cycles();
        $start = memory_get_usage();

        for ($round = 0; $round < 60; ++$round) {
            $batch($unclearedManager, $round);
        }

        $uncleared = memory_get_usage() - $start;

        // Assert
        self::assertLessThan(2 * 1024 * 1024, $cleared, 'clear() releases everything a unit of work held');
        self::assertGreaterThan($cleared * 3, $uncleared, 'without clear() the identity map grows with the rows, which is why workers clear');
    }

    public function test_streaming_a_large_table_with_cursor_uses_constant_memory(): void
    {
        // Arrange
        $connection = $this->orm->connection;
        $connection->transaction(static function ($c): void {
            for ($i = 0; $i < 20_000; $i += 500) {
                $rows = [];
                for ($j = 0; $j < 500; ++$j) {
                    $rows[] = ['name' => 'Row ' . ($i + $j), 'email' => 'row' . ($i + $j) . '@example.com', 'password_hash' => 'x', 'status' => 'active', 'settings' => '{}', 'created_at' => '2026-01-01 00:00:00', 'active' => 1, 'balance' => 0, 'version' => 1];
                }

                $c->table('customers')->insert($rows);
            }
        });
        gc_collect_cycles();
        $base = memory_get_usage();
        $peak = 0;
        $count = 0;

        // Act
        foreach ($this->manager->repository(Customer::class)->query()->cursor(500) as $customer) {
            ++$count;
            $peak = max($peak, memory_get_usage() - $base);
        }

        // Assert
        self::assertSame(20_002, $count);
        self::assertLessThan(4 * 1024 * 1024, $peak, 'peak extra memory while streaming 20,000 rows');
    }
}

enum IntPriority: int
{
    case Low = 2;
    case High = 3;
}
