<?php

declare(strict_types=1);

// Run by LockForUpdateConcurrencyTest as a separate process: one buyer taking units of a stock row,
// with a read-then-write decrement that only lockForUpdate() makes safe.
require __DIR__ . '/../../vendor/autoload.php';

use Trunk\Database\Connection\ConnectionFactory;
use Trunk\Database\Exception\QueryException;

$decoded = json_decode((string) getenv('TRUNK_LK_CONFIG'), true, 8, JSON_THROW_ON_ERROR);
$config = is_array($decoded) ? array_filter($decoded, is_string(...), ARRAY_FILTER_USE_KEY) : [];
$connection = new ConnectionFactory()->make('buyer', $config);
$buyer = (string) getenv('TRUNK_LK_BUYER');
$attempts = (int) getenv('TRUNK_LK_ATTEMPTS');
$report = ['bought' => 0, 'sold_out' => 0, 'conflicts' => 0];

for ($i = 0; $i < $attempts; ++$i) {
    try {
        $outcome = $connection->transaction(static function () use ($connection, $buyer): string {
            $row = $connection->table('trunk_lk_stock')->where('sku', 'A-1')->lockForUpdate()->first();
            $onHand = is_numeric($row['on_hand'] ?? null) ? (int) $row['on_hand'] : -1;

            if ($onHand < 1) {
                return 'sold_out';
            }

            usleep(random_int(500, 3000)); // widen the window between the read and the write
            $connection->table('trunk_lk_stock')->where('sku', 'A-1')->update(['on_hand' => $onHand - 1]);
            $connection->table('trunk_lk_sales')->insert(['buyer' => $buyer]);

            return 'bought';
        });
        ++$report[$outcome];
    } catch (QueryException) {
        // SQLite: a second writer fails ("database is locked") instead of waiting for a row lock.
        ++$report['conflicts'];
    }
}

echo json_encode($report);
