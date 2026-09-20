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

Types: `string int float bool dateTime json enum`. Modifiers: `nullable() hidden() filterable() sortable()`. Relations: `hasMany hasOne belongsTo belongsToMany`. `scope('name')` applies a global scope (a service tagged `orm.scope`, e.g. a tenant filter); a scope that is named but not registered **fails closed**. `trunk orm:validate` checks every map without building.

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

## Development versus production

Development uses an interpreted mapper; `trunk build` generates hydrators into `build/orm.php` (no `eval`), about 1.4 µs per row against 2.6 µs. Both produce identical results.

## Exceptions

`EntityNotFound`, `MappingException`, `HydrationException` (corrupt database values never appear in messages), `InvalidFilter`, `UnknownProperty`, `RelationNotLoaded`, `StaleEntity`, `OrmException`.
