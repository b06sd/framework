<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Database;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Exception\MigrationException;
use Trunk\Database\Migration\MigrationRepository;
use Trunk\Database\Migration\MigrationStub;
use Trunk\Database\Migration\Migrator;
use Trunk\Database\Schema\Schema;
use Trunk\Tests\Support\DatabaseHarness;

final class MigratorTest extends TestCase
{
    private string $directory;

    private Connection $connection;

    private Migrator $migrator;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-mig-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->connection = new DatabaseHarness()->sqlite();
        $schema = new Schema($this->connection);
        $this->migrator = new Migrator($this->connection, $schema, new MigrationRepository($this->connection, $schema), $this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function test_migrate_runs_pending_migrations_once_in_order_and_records_a_batch(): void
    {
        // Arrange
        $this->people();
        $this->write('2026_01_02_000001_create_pets_table', "\$schema->create('pets', function (Blueprint \$t): void { \$t->id(); \$t->foreignId('person_id')->constrained('people')->onDelete('cascade'); });", "\$schema->dropIfExists('pets');");

        // Act
        $first = $this->migrator->migrate();
        $second = $this->migrator->migrate();
        $status = $this->migrator->status();

        // Assert
        self::assertSame(['2026_01_01_000001_create_people_table', '2026_01_02_000001_create_pets_table'], $first);
        self::assertSame([], $second);
        self::assertSame([true, true], array_column($status, 'ran'));
        self::assertSame([1, 1], array_column($status, 'batch'));
        self::assertTrue($this->connection->table('people')->insert(['name' => 'Ada', 'email' => 'a@x.dev']) === 1);
    }

    public function test_rollback_undoes_the_last_batch_or_a_number_of_steps(): void
    {
        // Arrange
        $this->people();
        $this->migrator->migrate();
        $this->write('2026_01_02_000001_create_pets_table', "\$schema->create('pets', function (Blueprint \$t): void { \$t->id(); });", "\$schema->dropIfExists('pets');");
        $this->migrator->migrate();
        $schema = new Schema($this->connection);

        // Act
        $lastBatch = $this->migrator->rollback();
        $stepped = $this->migrator->rollback(5);
        $empty = $this->migrator->rollback();

        // Assert
        self::assertSame(['2026_01_02_000001_create_pets_table'], $lastBatch);
        self::assertSame(['2026_01_01_000001_create_people_table'], $stepped);
        self::assertSame([], $empty);
        self::assertFalse($schema->hasTable('people'));
        self::assertFalse($schema->hasTable('pets'));
    }

    public function test_a_failing_migration_is_rolled_back_and_not_recorded_and_the_error_names_it(): void
    {
        // Arrange
        $this->write('2026_01_01_000001_broken', "\$schema->create('half', function (Blueprint \$t): void { \$t->id(); }); throw new \\RuntimeException('nope');");

        // Act
        try {
            $this->migrator->migrate();
            self::fail('Expected a MigrationException.');
        } catch (MigrationException $e) {
            // Assert
            self::assertStringContainsString('2026_01_01_000001_broken', $e->getMessage());
            self::assertStringContainsString('nope', $e->getMessage());
            self::assertFalse(new Schema($this->connection)->hasTable('half'));
            self::assertSame(['ran' => false, 'batch' => null], array_intersect_key($this->migrator->status()[0], ['ran' => 1, 'batch' => 1]));
        }
    }

    public function test_fresh_drops_everything_including_unknown_tables_and_migrates_again(): void
    {
        // Arrange
        $this->people();
        $this->migrator->migrate();
        $this->connection->execute('CREATE TABLE stray (a INTEGER)');
        $this->connection->table('people')->insert(['name' => 'Ada', 'email' => 'a@x.dev']);

        // Act
        $ran = $this->migrator->fresh();

        // Assert
        self::assertSame(['2026_01_01_000001_create_people_table'], $ran);
        self::assertFalse(new Schema($this->connection)->hasTable('stray'));
        self::assertSame(0, $this->connection->table('people')->count());
    }

    public function test_foreign_keys_are_enforced(): void
    {
        // Arrange
        $this->people();
        $this->write('2026_01_02_000001_create_pets_table', "\$schema->create('pets', function (Blueprint \$t): void { \$t->id(); \$t->foreignId('person_id')->constrained('people'); });");
        $this->migrator->migrate();

        // Act & Assert
        $this->expectException(\Trunk\Database\Exception\QueryException::class);
        $this->connection->table('pets')->insert(['person_id' => 42]);
    }

    public function test_schema_introspection_and_rename(): void
    {
        // Arrange
        $schema = new Schema($this->connection);
        $schema->create('t', function (\Trunk\Database\Schema\Blueprint $t): void {
            $t->id();
            $t->string('name');
        });

        // Act
        $schema->rename('t', 'u');

        // Assert
        self::assertTrue($schema->hasTable('u'));
        self::assertTrue($schema->hasColumn('u', 'name'));
        self::assertFalse($schema->hasColumn('u', 'nope'));
        self::assertFalse($schema->hasTable('t'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badFileNames(): iterable
    {
        yield 'no timestamp' => ['create_users.php'];
        yield 'uppercase' => ['2026_01_01_000001_Create.php'];
        yield 'extra dot' => ['2026_01_01_000001_x.php.txt.php'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badFileNames')]
    public function test_stray_or_malformed_files_are_rejected_not_run(string $name): void
    {
        // Arrange
        file_put_contents($this->directory . '/' . $name, '<?php echo "ran"; exit(9);');

        // Act & Assert
        $this->expectException(MigrationException::class);
        $this->migrator->migrate();
    }

    public function test_a_file_that_does_not_return_a_migration_is_refused(): void
    {
        // Arrange
        file_put_contents($this->directory . '/2026_01_01_000001_x.php', '<?php return 1;');

        // Act & Assert
        $this->expectException(MigrationException::class);
        $this->migrator->migrate();
    }

    public function test_make_migration_writes_a_loadable_file_and_never_overwrites_or_accepts_bad_names(): void
    {
        // Arrange
        $stub = new MigrationStub();
        $now = new DateTimeImmutable('2026-09-19 12:00:00');

        // Act
        $path = $stub->create($this->directory, 'create_customers_table', $now);
        $ran = $this->migrator->migrate();

        // Assert
        self::assertSame($this->directory . '/2026_09_19_120000_create_customers_table.php', $path);
        self::assertSame(['2026_09_19_120000_create_customers_table'], $ran);
        self::assertTrue(new Schema($this->connection)->hasTable('customers'));
        foreach (['create_customers_table', '../evil', 'Bad', ''] as $name) {
            try {
                $stub->create($this->directory, $name, $now);
                self::fail('Expected rejection of ' . $name);
            } catch (MigrationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function write(string $name, string $up, string $down = ''): void
    {
        file_put_contents($this->directory . '/' . $name . '.php', "<?php\nuse Trunk\\Database\\Migration\\Migration;\nuse Trunk\\Database\\Schema\\Blueprint;\nuse Trunk\\Database\\Schema\\Schema;\nreturn new Migration(up: function (Schema \$schema): void { {$up} }, down: function (Schema \$schema): void { {$down} });\n");
    }

    private function people(string $suffix = '000001_create_people_table'): void
    {
        $this->write('2026_01_01_' . $suffix, "\$schema->create('people', function (Blueprint \$t): void { \$t->id(); \$t->string('name'); \$t->string('email')->unique(); \$t->timestamps(); });", "\$schema->dropIfExists('people');");
    }
}
