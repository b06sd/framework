<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Orm;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Connection\QueryLog;
use Trunk\Database\Schema\Blueprint;
use Trunk\Database\Schema\Schema;
use Trunk\Orm\Compiler\OrmArtifact;
use Trunk\Orm\Compiler\OrmCodeGenerator;
use Trunk\Orm\Exception\MappingException;
use Trunk\Orm\Exception\OrmException;
use Trunk\Orm\Mapping\DevelopmentRegistry;
use Trunk\Orm\Mapping\EntityMap;
use Trunk\Orm\Mapping\MapBuilder;
use Trunk\Orm\Mapping\MappingRegistry;
use Trunk\Orm\Mapping\MetadataFactory;
use Trunk\Orm\UnitOfWork\EntityManager;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Orm\Invoice;
use Trunk\Tests\Fixtures\Orm\InvoiceLine;
use Trunk\Tests\Fixtures\Orm\InvoiceLineMap;
use Trunk\Tests\Fixtures\Orm\InvoiceMap;
use Trunk\Tests\Support\DatabaseHarness;
use Trunk\Tests\Support\RecordingChangeListener;

/**
 * Composite primary keys: an invoice line identified by (invoiceId, lineNo), with the generated and
 * the development mapper alike.
 */
final class CompositeKeyTest extends TestCase
{
    private string $directory;

    private Connection $connection;

    private QueryLog $log;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-ck-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->log = new QueryLog();
        $this->connection = new DatabaseHarness()->sqlite($this->log);
        $schema = new Schema($this->connection);
        $schema->create('invoices', static function (Blueprint $t): void {
            $t->id();
            $t->string('number');
        });
        $schema->create('invoice_lines', static function (Blueprint $t): void {
            $t->integer('invoice_id');
            $t->integer('line_no');
            $t->string('sku');
            $t->integer('quantity');
            $t->primary(['invoice_id', 'line_no']);
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
    public function test_rows_are_found_updated_and_deleted_by_their_whole_key(string $mapper): void
    {
        // Arrange: two lines that share an invoice id, one that shares a line number
        $manager = $this->manager($mapper);

        foreach ([[1, 1, 'A-1'], [1, 2, 'B-2'], [2, 1, 'C-3']] as [$invoice, $line, $sku]) {
            $manager->persist(new InvoiceLine($invoice, $line, $sku));
        }

        $manager->flush();
        $fresh = $this->manager($mapper)->repository(InvoiceLine::class);

        // Act
        $line = $fresh->find(['invoiceId' => 1, 'lineNo' => 2]);
        self::assertInstanceOf(InvoiceLine::class, $line);
        $line->quantity = 5;
        $fresh->query()->first();   // loading again must not give a second object for a managed row

        // Assert
        self::assertSame('B-2', $line->sku);
        self::assertSame($line, $fresh->find(['lineNo' => 2, 'invoiceId' => 1]), 'one object per row, whatever the key order');
        self::assertNull($fresh->find(['invoiceId' => 2, 'lineNo' => 2]));
    }

    #[DataProvider('mappers')]
    public function test_an_update_or_delete_touches_exactly_the_row_with_that_whole_key(string $mapper): void
    {
        // Arrange
        $this->seed();
        $manager = $this->manager($mapper);
        $lines = $manager->repository(InvoiceLine::class);
        $target = $lines->findOrFail(['invoiceId' => 1, 'lineNo' => 2]);
        $doomed = $lines->findOrFail(['invoiceId' => 2, 'lineNo' => 1]);

        // Act
        $target->quantity = 9;
        $manager->remove($doomed);
        $manager->flush();

        // Assert
        $rows = $this->connection->table('invoice_lines')->orderBy('invoice_id')->orderBy('line_no')->get();
        self::assertSame([[1, 1, 1], [1, 2, 9], [3, 1, 1]], array_map(static fn(array $r): array => [$r['invoice_id'], $r['line_no'], $r['quantity']], $rows));
    }

    #[DataProvider('mappers')]
    public function test_a_key_property_cannot_change(string $mapper): void
    {
        // Arrange
        $this->seed();
        $manager = $this->manager($mapper);
        $line = $manager->repository(InvoiceLine::class)->findOrFail(['invoiceId' => 1, 'lineNo' => 1]);

        // Act
        $line->lineNo = 7;

        // Assert
        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('Primary keys are immutable');
        $manager->flush();
    }

    public function test_find_needs_every_key_property_and_only_those(): void
    {
        // Arrange
        $lines = $this->manager('development')->repository(InvoiceLine::class);

        // Act & Assert
        foreach (["find(1)" => 1, 'one property' => ['invoiceId' => 1], 'a stranger' => ['invoiceId' => 1, 'lineNo' => 1, 'sku' => 'x']] as $case => $id) {
            try {
                $lines->find($id);
                self::fail($case . ' should be refused');
            } catch (OrmException $e) {
                self::assertStringContainsString('invoiceId', $e->getMessage(), $case);
            }
        }
    }

    public function test_the_cursor_walks_a_composite_key_without_skipping_or_repeating(): void
    {
        // Arrange: lines that share their first key column across chunk boundaries
        $manager = $this->manager('generated');

        foreach ([[1, 1], [1, 2], [1, 3], [2, 1], [2, 2], [3, 1], [3, 2]] as [$invoice, $line]) {
            $manager->persist(new InvoiceLine($invoice, $line, 'S' . $invoice . $line));
        }

        $manager->flush();

        // Act
        $seen = [];

        foreach ($this->manager('generated')->repository(InvoiceLine::class)->query()->cursor(2) as $line) {
            $seen[] = $line->invoiceId . '-' . $line->lineNo;
        }

        // Assert
        self::assertSame(['1-1', '1-2', '1-3', '2-1', '2-2', '3-1', '3-2'], $seen);
    }

    public function test_relations_to_and_from_a_composite_keyed_entity_work_on_single_columns(): void
    {
        // Arrange
        $this->seed();
        $manager = $this->manager('generated');

        // Act
        $invoice = $manager->repository(Invoice::class)->query()->where('number', 'INV-1')->with('lines')->first();
        $line = $manager->repository(InvoiceLine::class)->query()->with('invoice')->first();

        // Assert
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame(['A', 'B'], array_map(static fn(object $l): string => $l instanceof InvoiceLine ? $l->sku : '', $manager->relatedMany($invoice, 'lines')));
        self::assertInstanceOf(InvoiceLine::class, $line);
        $owner = $manager->relatedOne($line, 'invoice');
        self::assertInstanceOf(Invoice::class, $owner);
        self::assertSame('INV-1', $owner->number);
    }

    public function test_a_change_listener_gets_the_key_by_property(): void
    {
        // Arrange
        $recorder = new RecordingChangeListener();
        $manager = new EntityManager($this->connection, $this->registry('development'), [], [$recorder]);

        // Act
        $manager->persist(new InvoiceLine(4, 2, 'Z'));
        $manager->flush();

        // Assert
        self::assertSame(['invoiceId' => 4, 'lineNo' => 2], $recorder->batches[0]->all()[0]->id);
    }

    public function test_the_map_is_checked(): void
    {
        // Arrange
        $cases = [
            'one property' => [static fn(MapBuilder $m) => $m->key('invoiceId'), 'key() takes two or more different properties'],
            'not mapped' => [static fn(MapBuilder $m) => $m->key('invoiceId', 'nope'), 'key() names "nope", which is not mapped'],
            'with id() too' => [static function (MapBuilder $m): void {
                $m->id('lineNo');
                $m->key('invoiceId', 'lineNo');
            }, 'not both'],
        ];

        foreach ($cases as $case => [$define, $message]) {
            $map = new class ($define) implements EntityMap {
                public function __construct(private readonly Closure $define) {}

                public function entity(): string
                {
                    return InvoiceLine::class;
                }

                public function define(MapBuilder $map): void
                {
                    $map->table('invoice_lines');
                    $map->int('invoiceId');
                    ($this->define)($map);
                    $map->string('sku');
                    $map->int('quantity');
                }
            };

            // Act & Assert
            try {
                new MetadataFactory()->build([$map]);
                self::fail($case . ' should be refused');
            } catch (MappingException $e) {
                self::assertStringContainsString($message, implode("\n", $e->errors), $case);
            }
        }
    }

    public function test_a_relation_that_would_join_on_a_composite_key_must_name_its_column(): void
    {
        // Arrange: a hasMany from the composite-keyed line, with no local key named
        $lineMap = new class implements EntityMap {
            public function entity(): string
            {
                return InvoiceLine::class;
            }

            public function define(MapBuilder $map): void
            {
                $map->table('invoice_lines');
                $map->int('invoiceId');
                $map->int('lineNo');
                $map->key('invoiceId', 'lineNo');
                $map->string('sku');
                $map->int('quantity');
                $map->hasMany('siblings', Invoice::class, foreignKey: 'id');
            }
        };

        // Act & Assert
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('would join on a composite key');
        new MetadataFactory()->build([$lineMap, new InvoiceMap()]);
    }

    private function seed(): void
    {
        $this->connection->table('invoices')->insert([['number' => 'INV-1'], ['number' => 'INV-2']]);
        $this->connection->table('invoice_lines')->insert([
            ['invoice_id' => 1, 'line_no' => 1, 'sku' => 'A', 'quantity' => 1],
            ['invoice_id' => 1, 'line_no' => 2, 'sku' => 'B', 'quantity' => 1],
            ['invoice_id' => 2, 'line_no' => 1, 'sku' => 'C', 'quantity' => 1],
            ['invoice_id' => 3, 'line_no' => 1, 'sku' => 'D', 'quantity' => 1],
        ]);
    }

    private function manager(string $mapper): EntityManager
    {
        return new EntityManager($this->connection, $this->registry($mapper));
    }

    private function registry(string $mapper): MappingRegistry
    {
        if ($mapper === 'development') {
            return new DevelopmentRegistry([InvoiceMap::class, InvoiceLineMap::class]);
        }

        $file = $this->directory . '/orm.php';

        if (!is_file($file)) {
            file_put_contents($file, new OrmCodeGenerator()->generate(new MetadataFactory()->build([new InvoiceMap(), new InvoiceLineMap()])));
        }

        return OrmArtifact::load($file);
    }
}
