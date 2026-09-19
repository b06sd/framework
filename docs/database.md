# Database

`trunk package:install database` (also pulled in by `orm` and `queue`). Drivers: **SQLite, MySQL, PostgreSQL** through PDO. Settings come from `.env` (`DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`); the password uses `secret()` so it is never compiled into `build/`. Named connections live in `config/database.php`.

Inject `Connection` (the default connection), `Schema`, or `ConnectionManager` for named connections. Connections open lazily, reconnect once if the server went away outside a transaction, and cache prepared statements.

## Query builder

Every value is a bound parameter. Table and column names are validated (letters, digits, underscores; `table.column`; `name as alias`); anything else needs an explicit `Raw`.

```php
$users = $db->table('users')
    ->select('id', 'name')
    ->where('active', true)
    ->where('age', '>', 18)
    ->orWhere('role', 'admin')
    ->whereIn('country', ['NG', 'GH'])
    ->orderBy('name')
    ->limit(20)->offset(40)
    ->get();                                   // list of associative arrays

$row  = $db->table('users')->where('email', $email)->first();      // ?array
$id   = $db->table('users')->insertGetId(['name' => 'Ada', 'email' => $email]);
$n    = $db->table('users')->where('id', $id)->update(['name' => 'Ada L.']);
$db->table('sessions')->where('last_activity', '<', $cutoff)->delete();
$db->table('counters')->where('id', 1)->update(['hits' => new Raw('hits + 1')]);   // atomic increment
```

Also: `join`, `leftJoin`, `groupBy`, `having`, `whereNull`, `whereBetween`, `distinct`, `count`, `sum`, `avg`, `min`, `max`, `exists`, `value`, `pluck`, `find`, `insert` (one or many rows), `insertGetIds`, `forPage`. **`update()` and `delete()` without a `where()` throw** unless you call `unrestricted()`. `LIKE` values are escaped for you. Direct SQL: `select`, `selectOne`, `scalar`, `execute` with `?` bindings.

**Text containing a NUL byte:** PostgreSQL silently cuts the text at it (MySQL and SQLite keep it). The ORM refuses such values on PostgreSQL; with the query builder, reject them yourself.

## Transactions

```php
$db->transaction(function (Connection $db): void {
    $db->table('accounts')->where('id', 1)->update(['balance' => new Raw('balance - 10')]);
    $db->table('accounts')->where('id', 2)->update(['balance' => new Raw('balance + 10')]);
});     // any Throwable rolls back and is rethrown; nested calls use savepoints
```

A queue job or web request that leaves a transaction open is detected and rolled back.

## Schema and migrations

`trunk make:migration create_orders_table` creates `database/migrations/<timestamp>_create_orders_table.php` returning a `Migration(up:, down:)`.

```php
$schema->create('orders', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
    $table->string('reference', 40)->unique();
    $table->decimal('total', 10, 2)->default(0);
    $table->boolean('paid')->default(false);
    $table->json('meta')->nullable();
    $table->timestamps();
    $table->index(['customer_id', 'paid']);
});
$schema->table('orders', fn (Blueprint $t) => $t->string('note')->nullable());     // alter
```

Column types: `id string text integer bigInteger boolean decimal float date dateTime timestamp json uuid binary foreignId`. Modifiers: `nullable default unsigned unique index primary constrained onDelete`. Schema: `create table drop dropIfExists rename hasTable hasColumn dropAllTables`.

Commands: `trunk migrate`, `migrate:status`, `migrate:rollback [--step=N]`, `migrate:fresh` (drops everything; **refused in production**).

## Exceptions

`ConnectionException`, `QueryException` (message never contains SQL values or the server's text; the SQLSTATE class is kept), `InvalidQueryException`, `SchemaException`, `MigrationException`. Debug SQL (placeholders only) is on the exception; `log_queries` (on in debug) feeds the ORM's N+1 detector.
