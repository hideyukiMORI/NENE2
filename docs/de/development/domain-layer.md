# Domain-Layer-Richtlinie

NENE2 trennt Framework-Infrastruktur (HTTP-Runtime, DI, Config, Datenbankadapter) von Anwendungslogik (Use-Cases, Repositories, Domain-Regeln). Dieses Dokument definiert die Konventionen für die Anwendungsschicht zwischen HTTP-Handlern und Datenbankadaptern.

## Position

Der Domain-Layer ist die Menge von Use-Cases und Repository-Interfaces, die ausdrücken, was die Anwendung tut, unabhängig davon, wie Anfragen ankommen oder Daten gespeichert werden.

```text
HTTP-Handler (dünn)
  → UseCase (Anwendungslogik, Business-Invarianten)
    → RepositoryInterface (Datenzugriffsvertrag)
      → PdoRepositoryAdapter (Persistenzdetail)
```

Framework-Infrastruktur lebt in `src/`. Anwendungsspezifische Use-Cases und Repository-Interfaces sollten in einem Namespace leben, den das Client-Projekt kontrolliert. NENE2 bietet Konventionen und minimale Arbeitsbeispiele; es erzwingt keinen Namespace für Anwendungscode.

## UseCase-Konvention

Ein Use-Case drückt eine Anwendungsoperation aus. Er empfängt ein readonly-Eingabe-DTO, setzt Business-Invarianten durch und gibt eine typisierte Ausgabe zurück.

### Interface-Form

```php
interface CreateItemUseCaseInterface
{
    public function execute(CreateItemInput $input): CreateItemOutput;
}
```

Regeln:

- Eine Methode pro Use-Case-Interface, immer `execute` genannt.
- Eingabe und Ausgabe sind typisierte readonly-DTOs, keine rohen Arrays oder PSR-7-Objekte.
- Das Interface lebt neben oder über seinen Adaptern, nicht in einem Framework-Verzeichnis.
- Use-Cases können domänenspezifische Ausnahmen für Invariantenverletzungen werfen, die Aufrufer behandeln müssen.
- Use-Cases kennen kein HTTP, Sessions, Templates oder Queues.
- Use-Cases rufen den PSR-11-Container nicht direkt auf.

### Eingabe-DTO

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

- Standardmäßig `readonly` und `final`.
- Der Konstruktor empfängt bereits validierte Werte; Format-Validierung erfolgt im Handler vor dem Aufruf des Use-Cases.
- Business-Invarianten (Eindeutigkeit, Zustandsregeln) werden im Use-Case geprüft, nicht hier.

### Ausgabe-DTO

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

- Nur das tragen, was Aufrufer benötigen.
- Auch bei seiteneffekthaften Operationen eine typisierte Ausgabe zurückgeben.

### Implementierung

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

- Nur Konstruktorinjektion.
- Keine `new`-Aufrufe für Abhängigkeiten, die testbar sein müssen.
- Datenbanktransaktionen gehören zum Adapter oder einem Transaktionsmanager-Service.

## Repository-Interface-Konvention

Ein Repository-Interface beschreibt einen Datenzugriffsvertrag für ein Aggregat oder Domain-Konzept. Adapter implementieren es.

### Interface-Form

```php
interface ItemRepositoryInterface
{
    public function findById(int $id): ?Item;
    public function existsByName(string $name): bool;
    public function save(Item $item): int;
}
```

Regeln:

- Methoden verwenden Domain-Begriffe, keine SQL-Verben. `findById`, nicht `selectById`.
- Rückgabetypen verwenden Domain-Objekte oder Primitive, keine PDO-Ergebniszeilen oder rohe Arrays.
- Nullable-Rückgabe (`?Item`) statt Exception werfen, wenn Abwesenheit ein gültiger Fall ist.
- Interfaces leben im Anwendungs-Namespace, nicht in `src/Database/`.

### Domain-Objekt

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

- `id` ist vor der Persistenz nullable.
- Domain-Objekte frei von ORM-Annotationen oder Datenbankkopplung halten.

### PDO-Adapter

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

- `DatabaseQueryExecutorInterface` aus `src/Database/` verwenden, nicht rohes PDO.
- Alles SQL bleibt im Adapter.
- Datenbankzeilen-Werte beim Ausgeben in typisierte PHP-Werte casten.
- Adapter-Klassennamenpräfix: `Pdo` (z.B. `PdoItemRepository`).

## Handler-(Controller-)Grenze

Handler bleiben dünn. Ihre Aufgabe ist es, den HTTP-Request in eine Use-Case-Eingabe zu mappen, den Use-Case aufzurufen und eine Antwort zurückzugeben.

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

Regeln:

- Handler enthalten keine Geschäftslogik.
- Format-Validierung und DTO-Konstruktion erfolgen hier; Business-Invarianten bleiben im Use-Case.
- Handler rufen Repositories nicht direkt auf.
- Handler empfangen den Use-Case durch Konstruktorinjektion, typisiert zum Interface.

## Code-Layout

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

Nach Domain-Konzept gruppieren, nicht nach Layer-Typ.

## PSR-11-Container-Verkabelung

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

## Tests

### Use-Case-Unit-Tests

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
}
```

### Repository-Adapter-Integrationstests

```bash
docker compose run --rm app composer test:database
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

## Fehlerbehandlung

- Benannte Domain-Ausnahmen für Business-Invariantenverletzungen werfen.
- Domain-Ausnahmen an der HTTP-Fehlergrenze zu Problem-Details mappen.
- Keine SQL-Fehler, Stack-Traces oder interne Bezeichner in Fehlerantworten exponieren.

## Nicht-Ziele

- Active Record oder Eloquent-artige Modelle.
- Automatische Code-Generierung aus OpenAPI oder Datenbankschemata.
- CQRS, Event Sourcing oder Saga-Muster im ersten Durchgang.
- Dependency Injection durch Reflexion oder Annotation.
- Service-Locator-Aufrufe innerhalb von Use-Cases oder Domain-Objekten.

## Verwandte Dokumentation

- Coding-Standards: `docs/development/coding-standards.md`
- Datenbanktest-Strategie: `docs/development/test-database-strategy.md`
- Endpoint-Scaffold-Workflow: `docs/development/endpoint-scaffold.md`
- Client-Projekt-Startanleitung: `docs/development/client-project-start.md`
