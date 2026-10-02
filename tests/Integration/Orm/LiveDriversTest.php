<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Orm;

use BcMath\Number;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Connection\ConnectionFactory;
use Trunk\Database\Connection\QueryLog;
use Trunk\Database\Schema\Blueprint;
use Trunk\Database\Schema\Schema;
use Trunk\Orm\Mapping\DevelopmentRegistry;
use Trunk\Orm\UnitOfWork\EntityManager;
use Trunk\Tests\Fixtures\Orm\InvoiceLine;
use Trunk\Tests\Fixtures\Orm\LiveInvoiceLineMap;
use Trunk\Tests\Fixtures\Orm\LiveStockItemMap;
use Trunk\Tests\Fixtures\Orm\StockItem;
use Trunk\Tests\Fixtures\Orm\Tag;
use Trunk\Tests\Fixtures\Orm\TagMap;

/**
 * Real MySQL and PostgreSQL, only when TRUNK_TEST_MYSQL_* / TRUNK_TEST_PGSQL_* (HOST, DATABASE,
 * USER, PASSWORD, optional PORT) are set. Skipped otherwise.
 */
final class LiveDriversTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        yield 'mysql' => ['mysql'];
        yield 'pgsql' => ['pgsql'];
    }

    #[DataProvider('drivers')]
    public function test_persist_flush_find_update_and_payloads_on_a_real_server(string $driver): void
    {
        // Arrange
        $prefix = 'TRUNK_TEST_' . ($driver === 'mysql' ? 'MYSQL' : 'PGSQL') . '_';
        $host = getenv($prefix . 'HOST');
        $database = getenv($prefix . 'DATABASE');

        if ($host === false || $database === false) {
            self::markTestSkipped('Set ' . $prefix . 'HOST and ' . $prefix . 'DATABASE to run this suite.');
        }

        $connection = new ConnectionFactory()->make('live', ['driver' => $driver, 'host' => $host, 'database' => $database, 'username' => getenv($prefix . 'USER') ?: '', 'password' => getenv($prefix . 'PASSWORD') ?: '', 'port' => (int) (getenv($prefix . 'PORT') ?: ($driver === 'mysql' ? 3306 : 5432))]);
        $schema = new Schema($connection);
        $schema->dropIfExists('tags');
        $schema->create('tags', function (Blueprint $t): void {
            $t->id();
            $t->string('label');
        });
        $payload = "'); DROP TABLE tags; --";

        try {
            $manager = new EntityManager($connection, new DevelopmentRegistry([TagMap::class]));

            // Act
            $tag = new Tag(label: $payload);
            $manager->persist($tag);
            $manager->flush();
            $tag->label = 'renamed';
            $manager->flush();
            $fresh = new EntityManager($connection, new DevelopmentRegistry([TagMap::class]))->repository(Tag::class)->findOrFail((int) $tag->id);

            // Assert
            self::assertSame('renamed', $fresh->label);
            self::assertTrue($schema->hasTable('tags'));
        } finally {
            $schema->dropIfExists('tags');
        }
    }

    #[DataProvider('drivers')]
    public function test_decimals_are_exact_and_locking_reads_lock_on_a_real_server(string $driver): void
    {
        // Arrange: 16 significant digits, more than a float can hold exactly
        $log = new QueryLog();
        $connection = $this->connection($driver, $log);
        $schema = new Schema($connection);
        $schema->dropIfExists('trunk_live_stock');
        $schema->create('trunk_live_stock', function (Blueprint $t): void {
            $t->id();
            $t->string('sku');
            $t->decimal('price', 16, 2);
            $t->decimal('on_hand', 14, 3);
            $t->decimal('cost', 14, 4)->nullable();
        });

        try {
            $manager = new EntityManager($connection, new DevelopmentRegistry([LiveStockItemMap::class]));
            $manager->persist(new StockItem(sku: 'A-1', price: new Number('12345678901234.56'), onHand: new Number('0.001'), cost: new Number('-1.5')));
            $manager->flush();

            // Act: lock the row, change it exactly, and read it back through a fresh unit of work
            $log->clear();
            $connection->transaction(static function () use ($connection): void {
                $manager = new EntityManager($connection, new DevelopmentRegistry([LiveStockItemMap::class]));
                $item = $manager->repository(StockItem::class)->query()->where('sku', 'A-1')->lockForUpdate()->first();
                self::assertInstanceOf(StockItem::class, $item);
                $item->price += new Number('0.01');
                $item->onHand -= new Number('0.001');
                $manager->flush();
            });
            $fresh = new EntityManager($connection, new DevelopmentRegistry([LiveStockItemMap::class]))->repository(StockItem::class)->query()->where('sku', 'A-1')->first();

            // Assert
            self::assertCount(1, array_filter($log->entries(), static fn(array $e): bool => str_ends_with($e['sql'], ' FOR UPDATE')), 'the locking read really says FOR UPDATE');
            self::assertInstanceOf(StockItem::class, $fresh);
            self::assertSame(['12345678901234.57', '0.000', '-1.5000'], [$fresh->price->value, $fresh->onHand->value, $fresh->cost?->value]);
        } finally {
            $schema->dropIfExists('trunk_live_stock');
        }
    }

    #[DataProvider('drivers')]
    public function test_composite_keys_find_update_and_page_on_a_real_server(string $driver): void
    {
        // Arrange
        $connection = $this->connection($driver, new QueryLog());
        $schema = new Schema($connection);
        $schema->dropIfExists('trunk_live_lines');
        $schema->create('trunk_live_lines', function (Blueprint $t): void {
            $t->integer('invoice_id');
            $t->integer('line_no');
            $t->string('sku');
            $t->integer('quantity');
            $t->primary(['invoice_id', 'line_no']);
        });

        try {
            $manager = new EntityManager($connection, new DevelopmentRegistry([LiveInvoiceLineMap::class]));

            foreach ([[1, 1], [1, 2], [1, 3], [2, 1], [2, 2]] as [$invoice, $line]) {
                $manager->persist(new InvoiceLine($invoice, $line, 'S' . $invoice . $line));
            }

            $manager->flush();

            // Act
            $fresh = new EntityManager($connection, new DevelopmentRegistry([LiveInvoiceLineMap::class]));
            $line = $fresh->repository(InvoiceLine::class)->findOrFail(['invoiceId' => 1, 'lineNo' => 2]);
            $line->quantity = 7;
            $fresh->flush();
            $paged = array_map(static fn(InvoiceLine $l): string => $l->invoiceId . '-' . $l->lineNo . ':' . $l->quantity, iterator_to_array(new EntityManager($connection, new DevelopmentRegistry([LiveInvoiceLineMap::class]))->repository(InvoiceLine::class)->query()->cursor(2), false));

            // Assert
            self::assertSame(['1-1:1', '1-2:7', '1-3:1', '2-1:1', '2-2:1'], $paged);
        } finally {
            $schema->dropIfExists('trunk_live_lines');
        }
    }

    private function connection(string $driver, QueryLog $log): Connection
    {
        $prefix = 'TRUNK_TEST_' . ($driver === 'mysql' ? 'MYSQL' : 'PGSQL') . '_';
        $host = getenv($prefix . 'HOST');
        $database = getenv($prefix . 'DATABASE');

        if ($host === false || $database === false) {
            self::markTestSkipped('Set ' . $prefix . 'HOST and ' . $prefix . 'DATABASE to run this suite.');
        }

        return new ConnectionFactory()->make('live', ['driver' => $driver, 'host' => $host, 'database' => $database, 'username' => getenv($prefix . 'USER') ?: '', 'password' => getenv($prefix . 'PASSWORD') ?: '', 'port' => (int) (getenv($prefix . 'PORT') ?: ($driver === 'mysql' ? 3306 : 5432))], $log);
    }
}
