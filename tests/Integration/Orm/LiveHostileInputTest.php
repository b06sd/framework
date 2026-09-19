<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Orm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Connection\ConnectionFactory;
use Trunk\Database\Schema\Blueprint;
use Trunk\Database\Schema\Schema;
use Trunk\Orm\Exception\InvalidFilter;
use Trunk\Orm\Exception\UnknownProperty;
use Trunk\Orm\Mapping\DevelopmentRegistry;
use Trunk\Orm\UnitOfWork\EntityManager;
use Trunk\Tests\Fixtures\Orm\HardItem;
use Trunk\Tests\Fixtures\Orm\HardItemMap;

/**
 * Request-shaped hostile filter and sort values against every real database: a value a database
 * rejects (a NUL byte in PostgreSQL text, an out-of-range integer, invalid UTF-8) must come back as a
 * rejected filter or an empty result, never as a server error. SQLite always; MySQL and PostgreSQL
 * when TRUNK_TEST_MYSQL_* / TRUNK_TEST_PGSQL_* are set. Uses only a table named trunk_hard_items.
 */
final class LiveHostileInputTest extends TestCase
{
    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            new Schema($this->connection)->dropIfExists('trunk_hard_items');
            $this->connection->disconnect();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'mysql' => ['mysql'];
        yield 'pgsql' => ['pgsql'];
    }

    #[DataProvider('drivers')]
    public function test_hostile_values_never_become_server_errors_and_never_change_data(string $driver): void
    {
        // Arrange
        $connection = $this->connection = $this->connect($driver);
        $schema = new Schema($connection);
        $schema->dropIfExists('trunk_hard_items');
        $schema->create('trunk_hard_items', function (Blueprint $t): void {
            $t->id();
            $t->string('label');
            $t->integer('qty');
            $t->boolean('flag');
            $t->float('price');
        });
        $manager = new EntityManager($connection, new DevelopmentRegistry([HardItemMap::class]));

        for ($i = 0; $i < 30; ++$i) {
            $manager->persist(new HardItem(label: 'item ' . $i, qty: $i, flag: $i % 2 === 0, price: $i * 1.5));
        }

        $manager->flush();
        $manager->clear();
        $values = ['item 1', '', '%', '_', "' OR 1=1 --", "\0", "a\0b", "\xB1\x31", str_repeat('é', 3000), '0', '-1', '1.5', '1e999', 'NaN', '9223372036854775807', '99999999999999999999', -1, 0, 1, \PHP_INT_MAX, \PHP_INT_MIN, 1.5, -0.0, 1e308, true, false, null, [], ['item 1', "\0"], [1, 2, 3], "\u{202E}", "😀"];
        $properties = ['label', 'qty', 'flag', 'price'];
        $untyped = [];
        mt_srand(29);

        // Act
        for ($i = 0; $i < 400; ++$i) {
            $filter = [$properties[mt_rand(0, 3)] => $values[mt_rand(0, \count($values) - 1)]];

            try {
                $rows = $manager->repository(HardItem::class)->query()->readOnly()->filter($filter)->sortBy(['label', '-qty', 'price', '-price'][mt_rand(0, 3)])->limit(50)->get();
                self::assertLessThanOrEqual(30, \count($rows));
            } catch (InvalidFilter|UnknownProperty) {
                // A typed rejection is the correct answer to a value that means nothing for the column.
            } catch (Throwable $e) {
                $untyped[$e::class . ': ' . substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 100)] = $filter;
            }
        }

        // Assert
        self::assertSame([], array_map(static fn(array $f): string => json_encode($f, \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '?', $untyped));
        self::assertSame(30, $connection->table('trunk_hard_items')->count());
    }

    #[DataProvider('drivers')]
    public function test_text_with_a_nul_byte_is_stored_intact_or_refused_never_silently_cut(string $driver): void
    {
        // Arrange
        $connection = $this->connection = $this->connect($driver);
        $schema = new Schema($connection);
        $schema->dropIfExists('trunk_hard_items');
        $schema->create('trunk_hard_items', function (Blueprint $t): void {
            $t->id();
            $t->string('label');
            $t->integer('qty');
            $t->boolean('flag');
            $t->float('price');
        });
        $manager = new EntityManager($connection, new DevelopmentRegistry([HardItemMap::class]));
        $manager->persist(new HardItem(label: "hidden\0tail", qty: 1));

        // Act
        try {
            $manager->flush();
            $stored = $connection->table('trunk_hard_items')->value('label');
            $outcome = $stored === "hidden\0tail" ? 'intact' : 'cut';
        } catch (\Trunk\Orm\Exception\OrmException) {
            $outcome = 'refused';
            $manager->clear();
        }

        // Assert
        self::assertSame($driver === 'pgsql' ? 'refused' : 'intact', $outcome, 'PostgreSQL would cut the text at the NUL, so it is refused there; the others keep it');
        self::assertSame($driver === 'pgsql' ? 0 : 1, $connection->table('trunk_hard_items')->count());
    }

    private function connect(string $driver): Connection
    {
        if ($driver === 'sqlite') {
            return new ConnectionFactory()->make('hostile', ['driver' => 'sqlite', 'database' => ':memory:']);
        }

        $prefix = 'TRUNK_TEST_' . ($driver === 'mysql' ? 'MYSQL' : 'PGSQL') . '_';
        $host = getenv($prefix . 'HOST');
        $database = getenv($prefix . 'DATABASE');

        if ($host === false || $database === false) {
            self::markTestSkipped('Set ' . $prefix . 'HOST and ' . $prefix . 'DATABASE to run this suite.');
        }

        return new ConnectionFactory()->make('hostile', ['driver' => $driver, 'host' => $host, 'database' => $database, 'username' => getenv($prefix . 'USER') ?: '', 'password' => getenv($prefix . 'PASSWORD') ?: '', 'port' => (int) (getenv($prefix . 'PORT') ?: ($driver === 'mysql' ? 3306 : 5432))]);
    }
}
