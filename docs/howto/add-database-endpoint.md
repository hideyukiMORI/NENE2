# Add a Database-backed Endpoint

This guide shows how to add an endpoint that reads from and writes to a database, following NENE2's domain layer pattern.

**Prerequisite**: You have a working NENE2 application with a route registered. If not, start with [Add a custom route](./add-custom-route.md).

---

## The pattern

NENE2 uses a three-layer pattern between the HTTP handler and the database:

```
HTTP Handler
  ↓ calls
UseCase          ← business logic, no HTTP or database knowledge
  ↓ calls
RepositoryInterface ← database operations, defined as an interface
  ↓ implemented by
PdoRepository    ← the actual SQL queries
```

This is the same separation you get in FastAPI with a service layer, or in Node.js with a repository pattern. The HTTP handler stays thin; the use case holds the logic; the repository handles persistence.

---

## Example: a `Product` resource

We will build `GET /products/{id}` as a concrete example.

### 1 — Define the domain entity

Create `src/Product/Product.php`:

```php
<?php

declare(strict_types=1);

namespace MyApp\Product;

final readonly class Product
{
    public function __construct(
        public int    $id,
        public string $name,
        public int    $price,
    ) {}
}
```

`readonly` means properties are set once in the constructor and cannot change — equivalent to a frozen object in JavaScript or a dataclass with `frozen=True` in Python.

### 2 — Define the repository interface

Create `src/Product/ProductRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MyApp\Product;

interface ProductRepositoryInterface
{
    public function findById(int $id): ?Product;
}
```

The interface declares *what* can be done, not *how*. This lets you swap a real database for an in-memory fake in tests.

### 3 — Define the use case

Create `src/Product/GetProductByIdUseCase.php`:

```php
<?php

declare(strict_types=1);

namespace MyApp\Product;

final readonly class GetProductByIdUseCase
{
    public function __construct(private ProductRepositoryInterface $products) {}

    public function execute(int $id): ?Product
    {
        return $this->products->findById($id);
    }
}
```

The use case knows nothing about HTTP or SQL. It receives a repository and calls it. This makes it easy to test without a database.

### 4 — Implement the repository with PDO

Create `src/Product/PdoProductRepository.php`:

```php
<?php

declare(strict_types=1);

namespace MyApp\Product;

use PDO;

final readonly class PdoProductRepository implements ProductRepositoryInterface
{
    public function __construct(private PDO $pdo) {}

    public function findById(int $id): ?Product
    {
        $stmt = $this->pdo->prepare('SELECT id, name, price FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new Product(
            id:    (int) $row['id'],
            name:  (string) $row['name'],
            price: (int) $row['price'],
        );
    }
}
```

All SQL lives here. Nothing outside this class needs to know which database or query syntax is used.

### 5 — Wire it in the front controller

In `public/index.php`, connect the pieces and register the route:

```php
<?php

declare(strict_types=1);

use MyApp\Product\GetProductByIdUseCase;
use MyApp\Product\PdoProductRepository;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$psr17 = new Psr17Factory();
$json  = new JsonResponseFactory($psr17, $psr17);

// Wire the database and use case.
$pdo        = new PDO('mysql:host=127.0.0.1;dbname=myapp', 'user', 'password');
$useCase    = new GetProductByIdUseCase(new PdoProductRepository($pdo));

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [
        static function (Router $router) use ($json, $useCase): void {
            $router->get('/products/{id}', static function (ServerRequestInterface $req) use ($json, $useCase) {
                $params  = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
                $id      = (int) ($params['id'] ?? 0);
                $product = $useCase->execute($id);

                if ($product === null) {
                    return $json->create([
                        'type'   => 'https://nene2.dev/problems/not-found',
                        'title'  => 'Not Found',
                        'status' => 404,
                    ], 404);
                }

                return $json->create([
                    'id'    => $product->id,
                    'name'  => $product->name,
                    'price' => $product->price,
                ]);
            });
        },
    ],
))->create();

// ... request handling (same as tutorial)
```

> **Production note**: For larger applications, move the wiring to a service provider
> and inject typed config objects instead of raw PDO connection strings.
> See `src/DependencyInjection/` and `docs/development/domain-layer.md` for the full pattern.

> **Path parameters**: Route parameters like `{id}` are stored under a named constant, not as
> individual request attributes. Always use `Router::PARAMETERS_ATTRIBUTE` to extract them:
>
> ```php
> use Nene2\Routing\Router;
>
> $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
> $id     = (int) ($params['id'] ?? 0);
> ```
>
> Calling `$request->getAttribute('id')` directly returns `null` — a common source of silent 404s
> when the record lookup then fails against id `0`.

---

## Test the use case without a database

Because `GetProductByIdUseCase` depends on `ProductRepositoryInterface` (not `PdoProductRepository`), you can test it with a simple in-memory fake:

```php
final class InMemoryProductRepository implements ProductRepositoryInterface
{
    /** @param array<int, Product> $products */
    public function __construct(private array $products = []) {}

    public function findById(int $id): ?Product
    {
        return $this->products[$id] ?? null;
    }
}

// In your test:
$repo    = new InMemoryProductRepository([1 => new Product(1, 'Widget', 999)]);
$useCase = new GetProductByIdUseCase($repo);
$result  = $useCase->execute(1);

assert($result->name === 'Widget');
```

This is the same pattern as mocking a service in Jest or using a test double in pytest.

---

## Directory layout

Following this pattern, your project will grow into:

```
src/
  Product/
    Product.php                   ← domain entity
    ProductRepositoryInterface.php ← what can be done
    GetProductByIdUseCase.php     ← business logic
    PdoProductRepository.php      ← SQL implementation
public/
  index.php                       ← wiring + routes
```

Each resource gets its own directory. Keep the handler thin and the use case focused on one operation.

---

## Throw a validation error from a handler

When a handler needs to reject a request because a field value is out of range or violates a
business rule, throw `ValidationException`. `ErrorHandlerMiddleware` maps it to a
`422 validation-failed` Problem Details response automatically.

```php
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;

// Inside a handler method, after reading the request body:
if ($price <= 0) {
    throw new ValidationException([
        new ValidationError(
            field:   'price',
            message: 'Price must be greater than zero.',
            code:    'out_of_range',
        ),
    ]);
}
```

`ValidationError` requires three non-empty strings:

| Parameter | Purpose |
|---|---|
| `field` | The request field that failed (matches the key in the request body or path) |
| `message` | Human-readable description of the failure |
| `code` | Machine-readable error code (use snake_case, e.g. `required`, `out_of_range`, `too_long`) |

Multiple errors can be reported in one throw:

```php
throw new ValidationException([
    new ValidationError('name',  'Name must not be empty.',           'required'),
    new ValidationError('price', 'Price must be greater than zero.',  'out_of_range'),
]);
```

The resulting response body:

```json
{
    "type":   "https://nene2.dev/problems/validation-failed",
    "title":  "Validation Failed",
    "status": 422,
    "errors": [
        { "field": "name",  "message": "Name must not be empty.",          "code": "required" },
        { "field": "price", "message": "Price must be greater than zero.", "code": "out_of_range" }
    ]
}
```

---

## MySQL foreign key columns: use `'signed' => false`

When using MySQL with Phinx, primary key columns are created as `INT UNSIGNED AUTO_INCREMENT`
by default. Foreign key columns that reference them must also be unsigned — otherwise MySQL
rejects the migration:

```
Referencing column 'user_id' and referenced column 'id' in foreign key constraint are incompatible
```

SQLite does not enforce this type constraint, so the same migration runs fine in SQLite and
only fails when you switch to MySQL. To avoid the mismatch, always add `'signed' => false` to
integer columns that act as foreign keys:

```php
$this->table('registrations')
    ->addColumn('user_id',  'integer', ['null' => false, 'signed' => false])
    ->addColumn('event_id', 'integer', ['null' => false, 'signed' => false])
    ->addForeignKey('user_id',  'users',  'id', ['delete' => 'CASCADE'])
    ->addForeignKey('event_id', 'events', 'id', ['delete' => 'CASCADE'])
    ->create();
```

### If a migration fails partway through

Phinx's `change()` method cannot always roll back a `CREATE TABLE` that succeeded before a
later step failed. The table will exist in MySQL but will not appear in `phinxlog`, so the
next `migrations:migrate` fails with "Table already exists."

Manual cleanup:

```bash
# Connect to MySQL inside the container
docker compose exec db mysql -u <user> -p<password> <dbname>
DROP TABLE IF EXISTS registrations;
exit

# Re-run the migration
docker compose run --rm app composer migrations:migrate
```

Alternatively, split the migration into `up()` and `down()` methods instead of `change()` so
the down path is explicit and predictable.

---

## Initialize a SQLite database

When using SQLite (`DB_ADAPTER=sqlite`), there is no server to create the database; the `.db`
file is created on demand. You are responsible for applying the schema before the first request.
Two common patterns:

### Pattern A — `composer db:init` script (recommended)

Create `database/schema.sql`:

```sql
CREATE TABLE IF NOT EXISTS products (
    id    INTEGER PRIMARY KEY AUTOINCREMENT,
    name  TEXT    NOT NULL,
    price INTEGER NOT NULL
);
```

Add a script to `composer.json`:

```json
{
    "scripts": {
        "db:init": "php -r \"$pdo = new PDO('sqlite:' . getenv('DB_NAME')); $pdo->exec(file_get_contents('database/schema.sql')); echo 'Schema applied.' . PHP_EOL;\""
    }
}
```

Run once before starting the server:

```bash
DB_NAME=./myapp.db composer db:init
```

### Pattern B — Auto-initialize in the front controller

For small projects, check whether the file exists inside `public_html/index.php` before
creating the application:

```php
// Auto-create the SQLite schema on first run.
$dbFile = getenv('DB_NAME') ?: ':memory:';
if ($dbFile !== ':memory:' && !file_exists($dbFile)) {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
}
```

> **Trade-off**: Pattern A makes schema initialization explicit and easy to repeat in CI.
> Pattern B is convenient for development but couples startup logic to the front controller.

---

## Many-to-many relationships (M:N)

NENE2 has no special M:N abstraction — use a join table and add `attach` / `detach` methods to
the repository that owns the relationship.

### Schema

```sql
-- SQLite
CREATE TABLE IF NOT EXISTS post_tags (
    post_id INTEGER NOT NULL REFERENCES posts(id)  ON DELETE CASCADE,
    tag_id  INTEGER NOT NULL REFERENCES tags(id)   ON DELETE CASCADE,
    PRIMARY KEY (post_id, tag_id)
);
```

Enable cascade deletes in SQLite by adding `PRAGMA foreign_keys = ON` after opening the connection:

```php
$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
```

### Repository methods

Add `attachTag` and `detachTag` to `PostRepositoryInterface`:

```php
interface PostRepositoryInterface
{
    public function findById(int $id): ?Post;
    public function attachTag(int $postId, int $tagId): void;
    public function detachTag(int $postId, int $tagId): void;
    /** @return list<Tag> */
    public function findTags(int $postId): array;
}
```

Implement with `INSERT OR IGNORE` for idempotent attach and a plain `DELETE` for detach:

```php
public function attachTag(int $postId, int $tagId): void
{
    $this->pdo
        ->prepare('INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)')
        ->execute([$postId, $tagId]);
}

public function detachTag(int $postId, int $tagId): void
{
    $this->pdo
        ->prepare('DELETE FROM post_tags WHERE post_id = ? AND tag_id = ?')
        ->execute([$postId, $tagId]);
}

/** @return list<Tag> */
public function findTags(int $postId): array
{
    $stmt = $this->pdo->prepare(
        'SELECT t.id, t.name FROM tags t
         JOIN post_tags pt ON pt.tag_id = t.id
         WHERE pt.post_id = ?',
    );
    $stmt->execute([$postId]);

    return array_map(
        static fn (array $row) => new Tag((int) $row['id'], (string) $row['name']),
        $stmt->fetchAll(PDO::FETCH_ASSOC),
    );
}
```

### Route handler

```php
// POST /me/posts/{id}/tags/{tagId}
$router->post('/me/posts/{id}/tags/{tagId}', function (ServerRequestInterface $req) use ($json, $posts) {
    $params  = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $postId  = (int) ($params['id']    ?? 0);
    $tagId   = (int) ($params['tagId'] ?? 0);
    $posts->attachTag($postId, $tagId);

    return $json->create(['message' => 'Tag attached.']);
});
```

### MySQL note

MySQL does not support `INSERT OR IGNORE`. Use `INSERT IGNORE` instead:

```php
'INSERT IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)'
```

Or use `INSERT ... ON DUPLICATE KEY UPDATE` for the same idempotent effect.

---

---

## Handling UNIQUE constraint violations

Since **v1.5.23**, `PdoDatabaseQueryExecutor` automatically detects constraint violations (UNIQUE,
FK, NOT NULL, CHECK) and throws `DatabaseConstraintException` instead of the generic
`DatabaseConnectionException`. Catch it by type — no message string inspection needed:

```php
use Nene2\Database\DatabaseConstraintException;
use Nene2\Database\DatabaseConnectionException;

public function vote(int $pollId, string $voterKey): void
{
    try {
        $this->executor->execute(
            'INSERT INTO votes (poll_id, voter_key, created_at) VALUES (?, ?, ?)',
            [$pollId, $voterKey, date('Y-m-d H:i:s')],
        );
    } catch (DatabaseConstraintException) {
        // UNIQUE, FK, NOT NULL, or CHECK constraint violated.
        throw new DuplicateVoteException();
    }
    // DatabaseConnectionException (non-constraint errors) propagates up unchanged.
}
```

`DatabaseConstraintException extends DatabaseConnectionException`, so existing
`catch (DatabaseConnectionException)` blocks remain unaffected.

### Upsert pattern

To insert-or-update (upsert), catch `DatabaseConstraintException` and update instead:

```php
try {
    $id = $this->executor->insert('INSERT INTO ratings (...) VALUES (?)', [...]);
} catch (DatabaseConstraintException) {
    $this->executor->execute('UPDATE ratings SET score = ? WHERE item_id = ? AND rater_id = ?', [...]);
}
```

---

## SQLite foreign key enforcement

SQLite does **not** enforce `FOREIGN KEY` constraints by default. Each connection must explicitly
enable them with a PRAGMA:

```sql
PRAGMA foreign_keys = ON;
```

`PdoConnectionFactory` does not set this PRAGMA automatically. Applications that declare FK
constraints in their schema and want them enforced at the DB level must run the PRAGMA after
connecting.

### How to enable in tests

Open the PDO connection and run the PRAGMA before inserting test data:

```php
$pdo = new \PDO('sqlite:' . $dbFile);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents('database/schema.sql'));
```

### How to enable in production (front controller / service provider)

After calling `PdoConnectionFactory::create()`, run the PRAGMA on the returned connection.
The simplest approach is a custom `ServiceProvider`:

```php
use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\DependencyInjection\ContainerBuilder;

$builder->addProvider(new class implements \Nene2\DependencyInjection\ServiceProviderInterface {
    public function register(ContainerBuilder $b): void
    {
        // Wrap the existing factory to run the PRAGMA after every new connection.
        // This is only needed for SQLite with FK constraints.
    }
});
```

Or, for a simple project, run the PRAGMA explicitly after bootstrapping:

```php
$connection = $container->get(DatabaseConnectionFactoryInterface::class)->create();
$connection->getPdo()->exec('PRAGMA foreign_keys = ON');
```

> **Note**: If you are using SQLite only for development and FK enforcement is not critical
> (e.g., your application validates FKs at the repository layer), you can omit this PRAGMA.
> The FK declarations in your schema SQL remain as documentation of the intended relationships.

---

## Inserting a row and retrieving the generated ID

Use `insert()` to execute an INSERT and return the auto-generated primary key in one call:

```php
$id = $this->executor->insert(
    'INSERT INTO products (name, price, created_at) VALUES (?, ?, ?)',
    [$name, $price, $now],
);
$product = $this->findById($id);
```

`insert()` is equivalent to calling `execute()` then `lastInsertId()` separately:

```php
$this->executor->execute(
    'INSERT INTO products (name, price, created_at) VALUES (?, ?, ?)',
    [$name, $price, $now],
);
$id = $this->executor->lastInsertId();
```

> **PostgreSQL**: `lastInsertId()` returns `0` on PostgreSQL without a sequence name.
> Append `RETURNING id` to the INSERT and call `fetchOne()` instead.
> See [`use-postgresql.md`](use-postgresql.md) for the complete pattern.

---

## Full-text search with SQLite FTS5

SQLite 3.9+ (bundled with PHP) supports the FTS5 extension for efficient full-text search.
The recommended pattern uses a **content table** — the FTS index shadows a regular table,
with triggers keeping them in sync.

### Schema

```sql
CREATE TABLE IF NOT EXISTS articles (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    title   TEXT NOT NULL,
    body    TEXT NOT NULL
);

CREATE VIRTUAL TABLE IF NOT EXISTS articles_fts USING fts5(
    title,
    body,
    content='articles',
    content_rowid='id'
);

-- Keep FTS index in sync with the base table
CREATE TRIGGER IF NOT EXISTS articles_ai AFTER INSERT ON articles BEGIN
    INSERT INTO articles_fts(rowid, title, body) VALUES (new.id, new.title, new.body);
END;

CREATE TRIGGER IF NOT EXISTS articles_ad AFTER DELETE ON articles BEGIN
    INSERT INTO articles_fts(articles_fts, rowid, title, body) VALUES ('delete', old.id, old.title, old.body);
END;

CREATE TRIGGER IF NOT EXISTS articles_au AFTER UPDATE ON articles BEGIN
    INSERT INTO articles_fts(articles_fts, rowid, title, body) VALUES ('delete', old.id, old.title, old.body);
    INSERT INTO articles_fts(rowid, title, body) VALUES (new.id, new.title, new.body);
END;
```

### Query

Join the FTS table on `rowid` and use `MATCH` for the search predicate:

```php
$sql = 'SELECT a.*
          FROM articles a
          JOIN articles_fts fts ON a.id = fts.rowid
         WHERE fts.articles_fts MATCH ?
         ORDER BY a.created_at DESC
         LIMIT ? OFFSET ?';

$rows = $this->executor->fetchAll($sql, [$query . '*', $limit, $offset]);
```

Append `'*'` to the query string for **prefix matching** (`php*` matches "PHP", "phpstan", etc.).
Omit it for exact-word matching.

> **Combining with other filters**: When mixing FTS search with regular WHERE clauses
> (e.g., `AND source = ?`), add extra conditions after the `MATCH` predicate in the same
> WHERE clause. The FTS filter must come first so that it defines the rowid set that the
> regular filters then narrow.

---

## Aggregate queries with HAVING and PDO parameters (SQLite)

When filtering aggregated results with `HAVING` and a bound `?` parameter in SQLite, PDO
binds the value as a **string**. SQLite then performs a text comparison, which gives
incorrect results for multi-digit numbers:

```php
// BROKEN — PDO binds threshold as string "5"
// "10" <= "5" is TRUE in text comparison ("1" < "5" lexicographically)
$rows = $executor->fetchAll(
    "SELECT ..., SUM(qty) AS total
     FROM orders
     GROUP BY customer_id
     HAVING total <= ?",
    [5],
);
// Returns customers with total=10 — wrong!
```

**Fix**: wrap the parameter in `CAST(? AS INTEGER)` to force numeric comparison:

```php
// CORRECT — numeric comparison enforced
$rows = $executor->fetchAll(
    "SELECT ..., SUM(qty) AS total
     FROM orders
     GROUP BY customer_id
     HAVING total <= CAST(? AS INTEGER)",
    [5],
);
```

This only affects `HAVING` clauses with aggregate aliases. Regular `WHERE` comparisons
against integer columns work correctly without the cast because the column's declared type
provides the affinity.

> **MySQL / PostgreSQL**: This issue does not occur on MySQL or PostgreSQL, which always
> apply numeric affinity for integer column comparisons. The `CAST` is harmless on those
> databases, so keeping it is safe for cross-database code.

> **Pattern also applies to `HAVING COUNT(DISTINCT ...) = ?`**: The cast is required any time
> a bound integer placeholder appears in a `HAVING` clause, not just for aggregate alias comparisons.
> Example: `HAVING COUNT(DISTINCT tag) = CAST(? AS INTEGER)` for AND-mode tag filtering.

---

## Next steps

- Add OpenAPI documentation for your endpoint: see `docs/development/endpoint-scaffold.md`
- Add database migrations: see `docs/development/test-database-strategy.md`
- See NENE2's built-in Note example as a reference: `src/Example/Note/`
- PATCH endpoint pattern: see [`implement-patch-endpoint.md`](implement-patch-endpoint.md)
