# DatabaseManager

**DatabaseManager** is a small, readable database layer for the EchoFusion framework. It is built to be **replaced**: your code talks to a tiny contract, and the implementation behind it can be our PDO connection, Doctrine DBAL, or anything else you write, chosen in configuration.

It is also meant to show what happens behind the scenes: the whole package is a few hundred lines you can read in an afternoon.

```
your code ──► ConnectionInterface (echo-fusion/contracts)
                     ▲
        ┌────────────┼──────────────────┐
  PdoConnection   DoctrineConnection   your own class
   (built in)      (optional adapter)
```

## Installation

```bash
composer require echo-fusion/databasemanager
```

Optional, only for the Doctrine adapter:

```bash
composer require doctrine/dbal
```

## Requirements

* PHP 8.1, 8.2 or 8.3
* ext-pdo (and the PDO driver you use: `pdo_mysql`, `pdo_pgsql` or `pdo_sqlite`)

## Quick start

```php
use EchoFusion\DatabaseManager\DatabaseManager;

$databases = DatabaseManager::fromConfig([
    'default' => 'main',
    'connections' => [
        'main' => ['driver' => 'mysql', 'host' => 'db', 'dbname' => 'app', 'user' => 'app', 'password' => 'secret'],
    ],
]);

$db = $databases->connection();                 // created on first use, then reused

$user = $db->fetchOne('SELECT * FROM users WHERE id = :id', ['id' => 7]);   // array|null
$rows = $db->fetchAll('SELECT * FROM users WHERE id IN (:ids)', ['ids' => [1, 2, 3]]);
$count = $db->fetchValue('SELECT COUNT(*) FROM users');
$changed = $db->execute('UPDATE users SET active = ? WHERE age < ?', [0, 18]);   // affected rows
```

## The contract

`EchoFusion\Contracts\DatabaseManager\ConnectionInterface`:

| Method | |
| --- | --- |
| `fetchAll($sql, $params)` | every row, as associative arrays |
| `fetchOne($sql, $params)` | the first row, or `null` |
| `fetchValue($sql, $params)` | first column of the first row, or `null` |
| `execute($sql, $params)` | run INSERT/UPDATE/DELETE/DDL, returns affected rows |
| `lastInsertId($name)` | id of the last insert |
| `transactional($callback)` | commit on success, roll back and re-throw on error |
| `beginTransaction()`, `commit()`, `rollBack()`, `inTransaction()` | manual control |

`DatabaseManagerInterface` adds `connection(?string $name)`, `has()` and `getDefaultConnectionName()`.

### Parameters

* Values are **always bound**, never put into the SQL string. PHP types map to PDO types (`int`, `bool`, `null`, string).
* Use named (`:id`) or positional (`?`) placeholders.
* An **array** bound to a named placeholder expands to a list: `WHERE id IN (:ids)` with `['ids' => [1, 2, 3]]` becomes `IN (:ids_0, :ids_1, :ids_2)`. An empty array becomes `IN (NULL)`, which matches nothing. (A `:name` inside a quoted SQL string is replaced too, so keep such literals out of queries that bind arrays.)

### Transactions

```php
$db->transactional(function ($db) {
    $db->execute('UPDATE accounts SET balance = balance - ? WHERE id = ?', [100, 1]);
    $db->execute('UPDATE accounts SET balance = balance + ? WHERE id = ?', [100, 2]);
});
```

Transactions nest. The outermost one is a real transaction; inner ones are **savepoints**, so an inner failure that you catch rolls back only the inner part:

```php
$db->transactional(function ($db) {
    $db->execute('INSERT INTO log (msg) VALUES (?)', ['start']);

    try {
        $db->transactional(fn ($db) => $db->execute('INSERT INTO risky ...'));
    } catch (Throwable) {
        // "risky" was rolled back, "start" is still pending in the outer transaction
    }
});
```

### Errors

A failed statement throws `EchoFusion\DatabaseManager\Exceptions\QueryException`, which carries the SQL (`getSql()`) and the original exception, but never the bound values (they may be secrets). All exceptions extend `DatabaseException`.

## Query builder

A small fluent builder that works on **any** `ConnectionInterface`, so it keeps working if you swap PDO for Doctrine.

```php
use EchoFusion\DatabaseManager\Query\QueryBuilder;

$users = QueryBuilder::table($db, 'users')   // or $databases->table('users')
    ->select('id', 'name', 'created_at as joined')
    ->where('active', 1)
    ->where('age', '>=', 18)
    ->orWhere('role', 'admin')
    ->whereIn('country', ['IR', 'DE'])
    ->whereNull('deleted_at')
    ->orderBy('name')
    ->limit(20)
    ->offset(40)
    ->get();                                  // list of rows

$first = $query->first();                     // row or null
$name = $query->value('name');
$total = $query->count();
$exists = $query->exists();

$id = QueryBuilder::table($db, 'users')->insertGetId(['name' => 'Ann', 'age' => 30]);
QueryBuilder::table($db, 'users')->where('id', $id)->update(['age' => 31]);
QueryBuilder::table($db, 'users')->where('id', $id)->delete();

$query->toSql();                              // see the SQL
$query->getBindings();                        // and its parameters
```

Safety built in:

* Table and column names are validated (letters, digits, `_` and dots) and quoted, so they cannot carry SQL.
* Operators and order directions are whitelisted. Values are always bound.
* `update()` and `delete()` **refuse to run without a WHERE clause**. If you really mean every row: `->whereRaw('1 = 1')`.
* `whereRaw($sql, $bindings)` and `selectRaw($sql)` are the escape hatches; their SQL is used as written, so never put user input into it.

## Configuration

```php
return [
    'default' => 'main',
    'connections' => [
        // built in, PDO: mysql, pgsql or sqlite
        'main'  => ['driver' => 'mysql', 'host' => 'db', 'port' => 3306, 'dbname' => 'app',
                    'user' => 'app', 'password' => 'secret', 'charset' => 'utf8mb4'],
        'local' => ['driver' => 'sqlite', 'path' => __DIR__ . '/storage/app.sqlite'],   // or ':memory:'
        'pg'    => ['driver' => 'pgsql', 'host' => 'pg', 'dbname' => 'app', 'user' => 'app', 'password' => 'secret'],

        // replace the implementation: any class implementing ConnectionInterface,
        // created as `new YourClass($definition)` (without the "class" key)
        'orm'   => ['class' => DoctrineConnection::class, 'driver' => 'pdo_mysql', 'host' => 'db', 'dbname' => 'app',
                    'user' => 'app', 'password' => 'secret'],
    ],
];
```

Extra PDO attributes go in `'options' => [...]`; use `'dsn' => '...'` instead of the individual parts when you need an exact DSN. PDO is created with exceptions on, associative fetch mode and real prepared statements.

## Replacing the implementation

### With Doctrine DBAL

```php
use EchoFusion\DatabaseManager\Connections\DoctrineConnection;

$db = new DoctrineConnection(['driver' => 'pdo_mysql', 'host' => 'db', 'dbname' => 'app', 'user' => 'app', 'password' => 'secret']);
// or wrap a connection you already have: new DoctrineConnection($dbalConnection)
```

Everything written against `ConnectionInterface` (including the query builder) works the same. The test suite proves it: one shared set of behaviour tests runs against both `PdoConnection` and `DoctrineConnection`.

### With any other ORM or library

Implement `ConnectionInterface` (about ten small methods) in a class that takes the connection definition array, and reference it with `'class'`. Run the shared behaviour tests (`tests/ConnectionContractTestCase.php`) against it to check you kept the contract. For example, an adapter around Eloquent's or Cycle's connection can forward `fetchAll`, `execute` and the transaction methods one to one.

## Behind the scenes

* **Lazy:** the PDO object is created on the first query, so configuring ten connections costs nothing until one is used.
* **Typed binding:** each value is bound with the PDO type of its PHP type, so `LIMIT ?` and integer comparisons behave the same on every database.
* **Savepoints:** nesting counts levels; level 0 uses `BEGIN`, deeper levels use `SAVEPOINT ef_sp_N`.
* **List expansion:** `ParameterExpander` rewrites `:ids` into `:ids_0, :ids_1, ...` before preparing the statement.

## Service provider

`EchoFusion\DatabaseManager\Providers\DatabaseManagerProvider` registers `DatabaseManagerInterface` and `ConnectionInterface` (the default connection) in a container, and configures the manager from the `database` section of your configuration on `boot()`.

## Testing

```bash
composer test
```

Tests use an in-memory SQLite database.

## Credits

- [Amir Shadanfar](https://github.com/amir-shadanfar)

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
