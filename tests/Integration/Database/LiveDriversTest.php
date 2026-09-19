<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Database\Connection\ConnectionFactory;
use Trunk\Database\Exception\QueryException;
use Trunk\Database\Schema\Blueprint;
use Trunk\Database\Schema\Schema;

/**
 * Runs against real MySQL and PostgreSQL servers, only when TRUNK_TEST_MYSQL_* / TRUNK_TEST_PGSQL_*
 * variables (HOST, DATABASE, USER, PASSWORD, optional PORT) are set. Skipped otherwise.
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
    public function test_schema_crud_transactions_and_injection_payloads_on_a_real_server(string $driver): void
    {
        // Arrange
        $config = $this->config($driver);

        if ($config === null) {
            self::markTestSkipped('Set TRUNK_TEST_' . strtoupper($driver === 'mysql' ? 'MYSQL' : 'PGSQL') . '_HOST and _DATABASE to run this suite.');
        }

        $connection = new ConnectionFactory()->make('live', $config);
        $schema = new Schema($connection);
        $schema->dropIfExists('trunk_live_items');
        $schema->create('trunk_live_items', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('live')->default(true);
        });
        $payload = "'); DROP TABLE trunk_live_items; --";

        try {
            // Act
            $items = $connection->table('trunk_live_items');
            $items->insert([['name' => 'a'], ['name' => $payload]]);
            $connection->transaction(function () use ($items): void {
                $items->insert(['name' => 'in-tx']);
            });

            try {
                $connection->transaction(function () use ($items): void {
                    $items->insert(['name' => 'rolled-back']);
                    $items->insert(['name' => 'a']);
                });
            } catch (QueryException) {
            }

            // Assert
            self::assertSame(3, $items->count());
            self::assertSame($payload, $items->where('name', $payload)->value('name'));
            self::assertTrue($schema->hasTable('trunk_live_items'));
            self::assertTrue($schema->hasColumn('trunk_live_items', 'live'));
        } finally {
            $schema->dropIfExists('trunk_live_items');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function config(string $driver): ?array
    {
        $prefix = 'TRUNK_TEST_' . ($driver === 'mysql' ? 'MYSQL' : 'PGSQL') . '_';
        $host = getenv($prefix . 'HOST');
        $database = getenv($prefix . 'DATABASE');

        if ($host === false || $database === false) {
            return null;
        }

        return ['driver' => $driver, 'host' => $host, 'database' => $database, 'username' => getenv($prefix . 'USER') ?: '', 'password' => getenv($prefix . 'PASSWORD') ?: '', 'port' => (int) (getenv($prefix . 'PORT') ?: ($driver === 'mysql' ? 3306 : 5432))];
    }
}
