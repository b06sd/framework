# ORM

A data mapper with an identity map and a unit of work. **Entities are plain PHP classes; mapping is explicit in a separate class**, so nothing about the database leaks into your domain objects and nothing is discovered by reflection at run time. There is no Active Record and no lazy loading: relations load only when you ask (`with(...)`), so an accidental N+1 cannot hide.

## Coming from Laravel or another framework?

There is no `Model` class to extend. What other frameworks call a *model* is two small things here, kept apart on purpose:

| | Where | What it is |
| --- | --- | --- |
| **Entity** | `app/Entities/Customer.php` | a plain PHP class: what your domain *is*. No base class, no database code. |
| **Map** | `app/Orm/CustomerMap.php` | how it is *stored*: table, columns, relations. `implements EntityMap`. |

Why: an object that extends a base class and saves itself (Active Record) needs global state to find its database connection, mixes storage into every domain object, and hides queries behind property access (the N+1 problem). Here the entity stays testable without a database, the map is checked by `trunk orm:validate` and `trunk build` instead of by reflection at run time, and every query is explicit.

| In Eloquent | In Trunk |
| --- | --- |
| `class Customer extends Model { protected $hidden = ['password']; }` | an entity class plus `$map->string('passwordHash', 'password_hash')->hidden();` in its map |
| `Customer::find(7)`, `Customer::where('status', 'active')->get()` | `$manager->repository(Customer::class)->find(7)`, `->query()->where('status', 'active')->get()` (inject `EntityManager`; no static access) |
| `$customer->save()` | `$manager->persist($customer); $manager->flush();` (one unit of work, one transaction) |
| `public function orders(): HasMany` and lazy `$customer->orders` | `$map->hasMany('orders', Order::class, foreignKey: 'customerId')`, loaded only when you ask: `->with('orders')` |

## Entity and map

`trunk make:entity Post` creates both files: the entity in `app/Entities/` and its map in `app/Orm/` (maps are discovered as `app/Orm/*Map.php`; a map names its entity by class, so an entity may live anywhere, and projects that keep entities in `app/Orm` keep working).

```php
final class Customer
{
    public function __construct(
        public private(set) ?int $id = null,          // the id is set by the ORM only
        public string $name = '',
        public string $email = '',
        public string $passwordHash = '',
        public Status $status = Status::Active,        // backed enums are supported
        public ?DateTimeImmutable $deletedAt = null,
        public int $version = 1,
    ) {}
}

final class CustomerMap implements EntityMap
{
    public function entity(): string { return Customer::class; }

    public function define(MapBuilder $map): void
    {
        $map->table('customers');
        $map->id();
        $map->string('name')->filterable()->sortable();
        $map->string('email')->filterable();
        $map->string('passwordHash', 'password_hash')->hidden();     // property, then column; never selected, dumped or serialised
        $map->enum('status', Status::class)->filterable();
        $map->softDeletes();                                          // adds deletedAt; deleted rows vanish from every query
        $map->version();                                              // optimistic locking
        $map->hasMany('orders', Order::class, foreignKey: 'customerId');      // the property on Order that holds the customer id
    }
}
```

Types: `string int float decimal bool dateTime json enum` (`decimal` is for money and quantities, see below). Modifiers: `nullable() hidden() filterable() sortable()`. Relations: `hasMany hasOne belongsTo belongsToMany`. `scope('name')` applies a global scope (a service tagged `orm.scope`, e.g. a tenant filter); a scope that is named but not registered **fails closed**. `trunk orm:validate` checks every map without building.

**Composite keys.** A row identified by more than one column (an order line by its order and line number) maps the columns, then names them with `key()` instead of `id()`:

```php
$map->table('order_lines');
$map->int('orderId');
$map->int('lineNo');
$map->key('orderId', 'lineNo');            // PRIMARY KEY (order_id, line_no)
$map->belongsTo('order', Order::class, foreignKey: 'orderId');

$line = $lines->find(['orderId' => 7, 'lineNo' => 2]);
```

A composite key is never generated, so set its values before `persist()`; like any key, it cannot change afterwards. Updates and deletes match the whole key, `cursor()` walks it in order, and a change listener gets it as `['orderId' => 7, 'lineNo' => 2]`. Relations join on one column: from a composite-keyed entity, name it (`localKey` / `ownerKey`); a pivot-table relation (`belongsToMany`) needs single-column keys on both sides. `trunk build` reports anything else.

## Reading

Inject `EntityManager`.

```php
$repo = $manager->repository(Customer::class);
$one  = $repo->find(7);                                    // identity map first, then one query
$list = $repo->query()->where('status', 'active')->orderBy('name')->limit(50)->get();
$fast = $repo->query()->readOnly()->with('orders')->get();   // untracked entities; eager load: one query per relation
$page = $repo->query()->readOnly()->sortBy('-name')->paginate(page: 2, perPage: 20);   // Page: items, total
foreach ($repo->query()->cursor(500) as $c) { ... }        // streams a huge table with flat memory
```

`count()`, `exists()`, `first()`, `withHidden()`, `withTrashed()`, `onlyTrashed()`, `withoutScope('tenant')`. `$manager->nPlusOneFindings()` reports repeated similar queries in development.

### Untrusted input

Request parameters reach the query only through `filter()` and `sortBy()`, which accept **only properties marked `filterable()` / `sortable()`**; unknown properties, wrong types, too many values or fields are `InvalidFilter` / `UnknownProperty`: a `400 BAD_REQUEST` with the fixed message "The filter or sort is not valid." (never a database error, a 500, or your property names; the detail is in the log). An `UnknownProperty` raised by your own code, such as `with('typo')`, is a bug and stays a 500.

```php
$repo->query()->readOnly()->filter($request->getQueryParams())->sortBy($request->getQueryParams()['sort'] ?? 'name')->paginate();
$data = $repo->input((array) $request->getParsedBody(), allow: ['name', 'email']);   // typed values for named constructor arguments; only allowlisted fields
$manager->persist(new Customer(...$data));
```

## Writing

```php
$manager->persist(new Customer(name: 'Ada', email: 'ada@example.com'));
$customer->name = 'Ada Lovelace';       // tracked: changed columns only are updated
$manager->remove($old);
$manager->flush();                       // one transaction: inserts (batched), updates, deletes
```

`flush()` is all-or-nothing. After a failed flush call `clear()` and start again (the manager is not meant to be reused half-applied). `StaleEntity` is thrown when the optimistic-lock version changed under you. In a long-running process call `clear()` between units of work; a worker that does keeps flat memory, one that does not grows with the rows.

## Money, quantities and concurrent changes

**Exact numbers.** Never map a price, a cost or a quantity as `float`: `0.1 + 0.2` is not `0.3` in floating point, and the difference ends up in your totals. Map it as `decimal` and type the property as PHP's own exact number, `BcMath\Number` (needs the `bcmath` extension, which `trunk build` checks):

```php
use BcMath\Number;

final class StockItem
{
    public function __construct(
        public private(set) ?int $id = null,
        public string $sku = '',
        public Number $price = new Number('0'),    // $table->decimal('price', 12, 2)
        public Number $onHand = new Number('0'),   // $table->decimal('on_hand', 14, 3)
    ) {}
}

$map->decimal('price', 2);      // the scale: digits after the point, as in the column
$map->decimal('onHand', 3);
```

`Number` is immutable and exact, with ordinary operators: `$item->price * 3`, `$item->onHand -= new Number('2.5')`, `$a > $b`. Compare two amounts with `$a == $b` or `$a->compare($b) === 0`, never `===` (that asks whether they are the same object). Saving a value with more digits than the column holds (`19.999` into a scale of 2) is refused with the fix in the message rather than silently rounded: round it yourself, e.g. `$price->round(2)`. Query values may be more precise than the column (`where('price', '>', '9.995')`), and request input through `input()`/`filter()` must fit it (a 400 otherwise). Trailing zeros are not a change: `19.990` and `19.99` are the same amount and save nothing.

On SQLite, `NUMERIC` columns are stored as floating point, so exactness holds only up to about 15 significant digits there; MySQL and PostgreSQL store decimals exactly. Use SQLite for development and tests, not for a ledger.

**Two requests, one last item.** Read the row with a lock, decide, write, all in one transaction. Another transaction that wants the same row waits until yours ends, so stock can never be sold twice:

```php
$manager->connection()->transaction(function () use ($manager, $sku, $quantity): void {
    $item = $manager->repository(StockItem::class)->query()->where('sku', $sku)->lockForUpdate()->first()
        ?? throw new HttpException(404);

    if ($item->onHand < $quantity) {
        throw new HttpException(409, 'Not enough stock.');
    }

    $item->onHand -= $quantity;
    $manager->flush();
});
```

`lockForUpdate()` needs an open transaction (it refuses to run without one, since the lock would end with the SELECT) and works with `get()`/`first()`, not `count()`. Lock before anything else reads the row in that unit of work: an entity already loaded is returned only if its row is unchanged, otherwise `StaleEntity` is thrown instead of handing you stale numbers. Relations loaded with `with()` are not locked. For a single counter you do not need to read first: an atomic update with a guard does it in one statement (see [Database: locking rows](database.md#locking-rows)).

## Reacting to changes: audit trails, search indexes, events

A `ChangeListener` is told what every `flush()` wrote. Tag the service `orm.change_listener`:

```php
use Trunk\Auth\Auth;
use Trunk\Database\Connection\Connection;
use Trunk\Orm\UnitOfWork\{ChangeListener, Changes};

final readonly class AuditTrail implements ChangeListener
{
    public function __construct(private Connection $db, private Auth $auth) {}

    public function changed(Changes $changes): void
    {
        foreach ($changes as $change) {               // inserts, then updates, then deletes
            $this->db->table('audit_log')->insert([
                'entity' => $change->class,
                'entity_id' => (string) $change->id,
                'action' => $change->kind->value,         // insert, update, delete
                'user_id' => $this->auth->user()?->authId(),
                'before' => json_encode($change->before),
                'after' => json_encode($change->after),
            ]);
        }
    }
}

// in your module
$builder->scoped(AuditTrail::class);
$builder->tag('orm.change_listener', AuditTrail::class);
```

* Values are keyed by **property** and given in their **stored form** (a date as `2026-10-02 09:30:00`, a decimal as `"19.99"`, an enum as its value), ready to store or send. An update carries only the properties that changed; an insert only `after`; a delete only `before` (and `$change->soft` for a soft delete). The optimistic-lock version is left out.
* A `hidden()` property (a password hash) is listed when it changes, with the value `Change::HIDDEN`, never the real one.
* The listener runs **inside the flush's transaction**, after the writes and before the commit: what it writes through the connection commits or rolls back with the changes, so the audit trail can never miss a change or record one that did not happen. If it throws, the whole flush is rolled back. Write with the connection; calling `$manager->flush()` from a listener is refused.
* `$changes->of(StockItem::class)` keeps the changes to one class; `$change->entity` is the object itself.

Writes made with the query builder directly (bulk updates, `Raw` expressions) bypass the ORM and are not reported.

## Factories and seeding

`trunk make:factory Customer` reads Customer's map and writes `app/Factories/CustomerFactory.php`: one [fakerphp/faker](https://fakerphp.org) call per mapped column, keyed off its type. It needs Faker installed (`composer require --dev fakerphp/faker`; it is a dev dependency, not bundled) and the entity already mapped (`trunk make:entity` first).

```php
$factory = new CustomerFactory();
$customer = $factory->make();                              // realistic, unsaved
$vip = $factory->make(['email' => 'ada@example.com']);      // override anything by property name
```

The generated `id`, soft-delete and version columns are never faked (the ORM manages them); a hidden column is skipped too, since its constructor parameter already has a usable default.

`trunk db:seed` runs `database/seeders/DatabaseSeeder.php`, a file you write yourself:

```php
<?php

declare(strict_types=1);

use App\Factories\CustomerFactory;
use Trunk\Orm\UnitOfWork\EntityManager;

return static function (EntityManager $manager): void {
    $factory = new CustomerFactory();

    for ($i = 0; $i < 20; $i++) {
        $manager->persist($factory->make());
    }

    $manager->flush();
};
```

It refuses in production unless you pass `--force`, since it writes rows directly.

## Development versus production

Development uses an interpreted mapper; `trunk build` generates hydrators into `build/orm.php` (no `eval`), about 1.4 µs per row against 2.6 µs. Both produce identical results.

## Exceptions

`EntityNotFound`, `MappingException`, `HydrationException` (corrupt database values never appear in messages), `InvalidFilter`, `UnknownProperty`, `RelationNotLoaded`, `StaleEntity`, `OrmException`.
