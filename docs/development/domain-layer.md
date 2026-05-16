# Domain Layer Policy

NENE2 separates framework infrastructure (HTTP runtime, DI, config, database adapters) from application logic (use cases, repositories, domain rules). This document defines the conventions for the application layer that sits between HTTP handlers and database adapters.

## Position

The domain layer is the set of use cases and repository interfaces that express what the application does, independent of how requests arrive or how data is stored.

```text
HTTP handler (thin)
  → UseCase (application logic, business invariants)
    → RepositoryInterface (data access contract)
      → PdoRepositoryAdapter (persistence detail)
```

Framework infrastructure lives in `src/`. Application-specific use cases and repository interfaces should live in a namespace the client project controls. NENE2 provides conventions and minimal working examples; it does not force a namespace on application code.

## UseCase Convention

A use case expresses one application operation. It receives a readonly input DTO, enforces business invariants, and returns a typed output.

### Interface shape

```php
interface CreateItemUseCaseInterface
{
    public function execute(CreateItemInput $input): CreateItemOutput;
}
```

Rules:

- One method per use case interface, always named `execute`.
- Input and output are typed readonly DTOs, never raw arrays or PSR-7 objects.
- The interface lives next to or above its adapters, not inside a framework directory.
- Use cases may throw domain-specific exceptions for invariant violations that callers must act on.
- Use cases do not know about HTTP, sessions, templates, or queues.
- Use cases do not call the PSR-11 container directly.

### Input DTO

```php
final readonly class CreateItemInput
{
    public function __construct(
        public string $name,
        public int    $year,
    ) {
    }
}
```

- `readonly` and `final` by default.
- Constructor receives already-validated values; format validation happens in the handler before calling the use case.
- Business invariants (uniqueness, state rules) are checked inside the use case, not here.

### Output DTO

```php
final readonly class CreateItemOutput
{
    public function __construct(
        public int    $id,
        public string $name,
        public int    $year,
    ) {
    }
}
```

- Carry only what callers need. Do not expose persistence IDs or internal state unless the caller requires them.
- Return a typed output even for side-effectful operations; callers should not reach into repositories for the result.

### Implementation

```php
final class CreateItemUseCase implements CreateItemUseCaseInterface
{
    public function __construct(
        private readonly ItemRepositoryInterface $items,
    ) {
    }

    public function execute(CreateItemInput $input): CreateItemOutput
    {
        if ($this->items->existsByName($input->name)) {
            throw new ItemAlreadyExistsException($input->name);
        }

        $id = $this->items->save(new Item(name: $input->name, year: $input->year));

        return new CreateItemOutput(id: $id, name: $input->name, year: $input->year);
    }
}
```

- Constructor injection only.
- No `new` calls for dependencies that need to be testable.
- No database transactions here unless the use case owns the boundary; transactions belong in the adapter or a transaction manager service.

## Repository Interface Convention

A repository interface describes a data access contract for one aggregate or domain concept. Adapters implement it.

### Interface shape

```php
interface ItemRepositoryInterface
{
    public function findById(int $id): ?Item;
    public function existsByName(string $name): bool;
    public function save(Item $item): int;
}
```

Rules:

- Methods use domain terms, not SQL verbs. `findById`, not `selectById`.
- Return types use domain objects or primitives, not PDO result rows or raw arrays.
- Nullable return (`?Item`) instead of throwing for "not found" when absence is a valid case; throw only when absence signals a programming error.
- Interfaces live in the application namespace, not in `src/Database/`.

### Domain object

Use a small readonly class for the aggregate root when persistence details should stay inside the adapter:

```php
final readonly class Item
{
    public function __construct(
        public string $name,
        public int    $year,
        public ?int   $id = null,
    ) {
    }
}
```

- `id` is nullable before persistence.
- Keep domain objects free of ORM annotations or database coupling.

### PDO Adapter

```php
final class PdoItemRepository implements ItemRepositoryInterface
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $query,
    ) {
    }

    public function findById(int $id): ?Item
    {
        $row = $this->query->fetchOne('SELECT id, name, year FROM items WHERE id = ?', [$id]);

        return $row !== null
            ? new Item(name: $row['name'], year: (int) $row['year'], id: (int) $row['id'])
            : null;
    }

    public function existsByName(string $name): bool
    {
        return $this->query->fetchOne('SELECT 1 FROM items WHERE name = ?', [$name]) !== null;
    }

    public function save(Item $item): int
    {
        return $this->query->insert('INSERT INTO items (name, year) VALUES (?, ?)', [$item->name, $item->year]);
    }
}
```

- Use `DatabaseQueryExecutorInterface` from `src/Database/`, not raw PDO.
- All SQL stays inside the adapter. Use cases and domain objects have no SQL.
- Cast database row values to typed PHP values on the way out.
- Adapter class name prefix: `Pdo` (e.g., `PdoItemRepository`).

## Handler (Controller) Boundary

Handlers stay thin. Their job is to map the HTTP request into a use case input, call the use case, and return a response.

```php
final class CreateItemHandler
{
    public function __construct(
        private readonly CreateItemUseCaseInterface $useCase,
        private readonly JsonResponseFactory        $response,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) json_decode((string) $request->getBody(), associative: true);

        $input = new CreateItemInput(
            name: (string) ($body['name'] ?? ''),
            year: (int) ($body['year'] ?? 0),
        );

        $output = $this->useCase->execute($input);

        return $this->response->ok(['id' => $output->id, 'name' => $output->name, 'year' => $output->year]);
    }
}
```

Rules:

- Handlers do not contain business logic.
- Format validation and DTO construction happen here; business invariants stay in the use case.
- Handlers do not call repositories directly.
- Handlers receive the use case through constructor injection, typed to the interface.

## Code Layout

Framework infrastructure lives in `src/` under framework namespaces:

```
src/
  Database/     DB adapter boundaries (interfaces + PDO impls)
  DependencyInjection/
  Config/
  Http/
  Middleware/
  Routing/
  Validation/
  Error/
  View/
  Mcp/
```

Application-specific code (use cases, repositories, domain objects) should live in a directory and namespace that fits the client project. NENE2 example code uses `src/` directly until a client project defines its own namespace.

Suggested layout for a client project that extends NENE2:

```
src/
  Item/
    CreateItemInput.php
    CreateItemOutput.php
    CreateItemUseCaseInterface.php
    CreateItemUseCase.php
    Item.php
    ItemRepositoryInterface.php
    ItemAlreadyExistsException.php
    PdoItemRepository.php
    CreateItemHandler.php
```

Group by domain concept, not by layer type. Avoid `UseCases/`, `Repositories/`, `Handlers/` top-level directories that scatter a single concept across unrelated files.

## PSR-11 Container Wiring

Register use cases and repositories in a focused service provider:

```php
final class ItemServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->bind(ItemRepositoryInterface::class, static function (ContainerInterface $c): ItemRepositoryInterface {
            return new PdoItemRepository($c->get(DatabaseQueryExecutorInterface::class));
        });

        $builder->bind(CreateItemUseCaseInterface::class, static function (ContainerInterface $c): CreateItemUseCaseInterface {
            return new CreateItemUseCase($c->get(ItemRepositoryInterface::class));
        });

        $builder->bind(CreateItemHandler::class, static function (ContainerInterface $c): CreateItemHandler {
            return new CreateItemHandler(
                $c->get(CreateItemUseCaseInterface::class),
                $c->get(JsonResponseFactory::class),
            );
        });
    }
}
```

Rules:

- Bind interfaces, not concrete classes, as service identifiers where test substitution matters.
- Keep providers small and grouped by domain concept.
- Do not use the container as a service locator inside use cases or domain objects.
- Register the provider in `src/Http/RuntimeContainerFactory.php` or the equivalent bootstrap path.

## Testing

### Use case unit tests

Use case tests run without a database. Inject a test double that implements the repository interface.

```php
final class CreateItemUseCaseTest extends TestCase
{
    public function test_throws_when_item_name_already_exists(): void
    {
        $items = new InMemoryItemRepository([new Item(name: 'duplicate', year: 2026, id: 1)]);
        $useCase = new CreateItemUseCase($items);

        $this->expectException(ItemAlreadyExistsException::class);

        $useCase->execute(new CreateItemInput(name: 'duplicate', year: 2026));
    }

    public function test_returns_output_with_new_id(): void
    {
        $items = new InMemoryItemRepository([]);
        $useCase = new CreateItemUseCase($items);

        $output = $useCase->execute(new CreateItemInput(name: 'new-item', year: 2026));

        $this->assertSame('new-item', $output->name);
    }
}
```

An `InMemoryItemRepository` implements `ItemRepositoryInterface` and uses an in-memory array. It lives in `tests/` and is never shipped with production code.

### Repository adapter integration tests

Adapter tests exercise real SQL against the test database. Use the focused database test command:

```bash
docker compose run --rm app composer test:database
```

For adapter tests that require a service database:

```bash
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

Adapter test classes extend the project's database test case (see `docs/development/test-database-strategy.md`). They test SQL correctness, type casting, and edge cases that cannot be covered by in-memory doubles.

## Error Handling

- Throw named domain exceptions for business invariant violations (`ItemAlreadyExistsException`, `ItemNotFoundException`).
- Map domain exceptions to Problem Details at the HTTP error boundary, not inside use cases.
- Add a mapping entry in `src/Error/ErrorHandlerMiddleware.php` or the equivalent error handler.
- Do not expose SQL errors, stack traces, or internal identifiers in error responses.

## Non-Goals

- Active record or Eloquent-style models.
- Automatic code generation from OpenAPI or database schemas.
- CQRS, event sourcing, or saga patterns in the first pass.
- Dependency injection by reflection or annotation.
- Service locator calls inside use cases or domain objects.
- Business logic inside middleware or router callbacks.

## Related Work

- Coding standards: `docs/development/coding-standards.md`
- Request validation policy: `docs/development/request-validation.md`
- Database adapter boundaries: `src/Database/`
- Database test strategy: `docs/development/test-database-strategy.md`
- Dependency injection policy: `docs/development/dependency-injection.md`
- Endpoint scaffold workflow: `docs/development/endpoint-scaffold.md`
- Client project start guide: `docs/development/client-project-start.md`
- GitHub Issue: `#182`
