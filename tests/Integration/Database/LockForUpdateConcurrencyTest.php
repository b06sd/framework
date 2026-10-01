<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Connection\ConnectionFactory;
use Trunk\Database\Schema\Blueprint;
use Trunk\Database\Schema\Schema;

/**
 * The inventory race, for real: several processes buy from one stock row with a read-then-write
 * decrement. With lockForUpdate() nothing is ever oversold and no update is lost. Always runs on a
 * SQLite file; on MySQL and PostgreSQL when TRUNK_TEST_MYSQL_* / TRUNK_TEST_PGSQL_* are set (HOST,
 * DATABASE, USER, PASSWORD, optional PORT). It only creates and drops tables named trunk_lk_*.
 */
final class LockForUpdateConcurrencyTest extends TestCase
{
    private const int STOCK = 10;

    private const int BUYERS = 6;

    private const int ATTEMPTS = 5;

    /**
     * @return iterable<string, array{string}>
     */
    public static function databases(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'mysql' => ['mysql'];
        yield 'pgsql' => ['pgsql'];
    }

    #[DataProvider('databases')]
    public function test_concurrent_buyers_never_oversell_or_lose_an_update(string $driver): void
    {
        // Arrange: 10 in stock, 6 buyers making 30 attempts between them
        $file = sys_get_temp_dir() . '/trunk-lk-' . bin2hex(random_bytes(4)) . '.sqlite';
        $config = $this->config($driver, $file);

        if ($config === null) {
            self::markTestSkipped('Set TRUNK_TEST_' . strtoupper($driver === 'mysql' ? 'MYSQL' : 'PGSQL') . '_HOST and _DATABASE to run this suite.');
        }

        $connection = new ConnectionFactory()->make('setup', $config);
        $this->tables($connection, true);

        try {
            $connection->table('trunk_lk_stock')->insert(['sku' => 'A-1', 'on_hand' => self::STOCK]);

            // Act
            $processes = [];
            $pipes = [];

            for ($b = 0; $b < self::BUYERS; ++$b) {
                $process = proc_open([\PHP_BINARY, __DIR__ . '/../../Support/stock_buyer.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$b], null, [...getenv(), 'TRUNK_LK_CONFIG' => json_encode($config, \JSON_THROW_ON_ERROR), 'TRUNK_LK_BUYER' => 'b' . $b, 'TRUNK_LK_ATTEMPTS' => (string) self::ATTEMPTS]);
                self::assertIsResource($process);
                $processes[$b] = $process;
            }

            $totals = ['bought' => 0, 'sold_out' => 0, 'conflicts' => 0];

            foreach ($processes as $b => $process) {
                $output = (string) stream_get_contents($pipes[$b][1]);
                $errors = (string) stream_get_contents($pipes[$b][2]);
                proc_close($process);
                self::assertSame('', $errors, 'Buyer ' . $b . ' wrote errors: ' . $errors);
                $report = json_decode($output, true, 4, \JSON_THROW_ON_ERROR);
                self::assertIsArray($report);

                foreach ($totals as $key => $total) {
                    $totals[$key] = $total + (\is_int($report[$key] ?? null) ? $report[$key] : 0);
                }
            }

            // Assert: stock and sales always add up, and nothing is sold that was not there
            $onHand = $connection->table('trunk_lk_stock')->where('sku', 'A-1')->value('on_hand');
            $sales = $connection->table('trunk_lk_sales')->count();
            self::assertSame(self::BUYERS * self::ATTEMPTS, array_sum($totals), 'every attempt was accounted for');
            self::assertSame($totals['bought'], $sales, 'one sale row per successful purchase');
            self::assertSame(self::STOCK, $sales + (is_numeric($onHand) ? (int) $onHand : -1), 'no update was lost: stock + sales = what was there');
            self::assertLessThanOrEqual(self::STOCK, $sales, 'never oversold');

            if ($driver !== 'sqlite') {
                // A real row lock makes the second buyer wait its turn rather than fail.
                self::assertSame(0, $totals['conflicts']);
                self::assertSame(self::STOCK, $sales, 'everything in stock was sold');
                self::assertSame(0, is_numeric($onHand) ? (int) $onHand : -1);
            }
        } finally {
            $this->tables($connection, false);

            foreach (glob($file . '*') ?: [] as $leftover) {
                unlink($leftover);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function config(string $driver, string $file): ?array
    {
        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => $file];
        }

        $prefix = 'TRUNK_TEST_' . ($driver === 'mysql' ? 'MYSQL' : 'PGSQL') . '_';
        $host = getenv($prefix . 'HOST');
        $database = getenv($prefix . 'DATABASE');

        if ($host === false || $database === false) {
            return null;
        }

        return ['driver' => $driver, 'host' => $host, 'database' => $database, 'username' => getenv($prefix . 'USER') ?: '', 'password' => getenv($prefix . 'PASSWORD') ?: '', 'port' => (int) (getenv($prefix . 'PORT') ?: ($driver === 'mysql' ? 3306 : 5432))];
    }

    private function tables(Connection $connection, bool $create): void
    {
        $schema = new Schema($connection);
        $schema->dropIfExists('trunk_lk_sales');
        $schema->dropIfExists('trunk_lk_stock');

        if (!$create) {
            return;
        }

        $schema->create('trunk_lk_stock', function (Blueprint $t): void {
            $t->id();
            $t->string('sku', 32)->unique();
            $t->integer('on_hand');
        });
        $schema->create('trunk_lk_sales', function (Blueprint $t): void {
            $t->id();
            $t->string('buyer', 16);
        });
    }
}
