# Politique de couche domaine

NENE2 sépare l'infrastructure framework (runtime HTTP, DI, config, adaptateurs de base de données) de la logique applicative (cas d'utilisation, dépôts, règles domaine). Ce document définit les conventions pour la couche applicative qui se situe entre les handlers HTTP et les adaptateurs de base de données.

## Position

La couche domaine est l'ensemble des cas d'utilisation et interfaces de dépôt qui expriment ce que fait l'application, indépendamment de la façon dont les requêtes arrivent ou dont les données sont stockées.

```text
HTTP handler (fin)
  → UseCase (logique applicative, invariants métier)
    → RepositoryInterface (contrat d'accès aux données)
      → PdoRepositoryAdapter (détail de persistance)
```

L'infrastructure framework vit dans `src/`. Les cas d'utilisation et interfaces de dépôt spécifiques à l'application doivent vivre dans un namespace contrôlé par le projet client. NENE2 fournit des conventions et des exemples de travail minimaux ; il ne force pas un namespace sur le code applicatif.

## Convention UseCase

Un cas d'utilisation exprime une opération applicative. Il reçoit un DTO d'entrée readonly, applique les invariants métier et retourne une sortie typée.

### Forme de l'interface

```php
interface CreateItemUseCaseInterface
{
    public function execute(CreateItemInput $input): CreateItemOutput;
}
```

Règles :

- Une méthode par interface de cas d'utilisation, toujours nommée `execute`.
- L'entrée et la sortie sont des DTO readonly typés, jamais des tableaux bruts ou des objets PSR-7.
- L'interface vit à côté ou au-dessus de ses adaptateurs, pas dans un répertoire framework.
- Les cas d'utilisation peuvent lancer des exceptions spécifiques au domaine pour les violations d'invariants.
- Les cas d'utilisation ne connaissent pas HTTP, les sessions, les templates ou les queues.
- Les cas d'utilisation n'appellent pas directement le conteneur PSR-11.

### DTO d'entrée

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

- `readonly` et `final` par défaut.
- Le constructeur reçoit des valeurs déjà validées ; la validation de format se produit dans le handler avant d'appeler le cas d'utilisation.
- Les invariants métier (unicité, règles d'état) sont vérifiés dans le cas d'utilisation, pas ici.

### DTO de sortie

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

- Ne portez que ce dont les appelants ont besoin.
- Retournez une sortie typée même pour les opérations à effets secondaires.

### Implémentation

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

- Injection par constructeur uniquement.
- Pas d'appels `new` pour les dépendances testables.
- Les transactions de base de données appartiennent à l'adaptateur ou au service gestionnaire de transactions.

## Convention d'interface de dépôt

Une interface de dépôt décrit un contrat d'accès aux données pour un agrégat ou concept domaine. Les adaptateurs l'implémentent.

### Forme de l'interface

```php
interface ItemRepositoryInterface
{
    public function findById(int $id): ?Item;
    public function existsByName(string $name): bool;
    public function save(Item $item): int;
}
```

Règles :

- Les méthodes utilisent des termes domaine, pas des verbes SQL. `findById`, pas `selectById`.
- Les types de retour utilisent des objets domaine ou des primitives, pas des lignes PDO ou des tableaux bruts.
- Retour nullable (`?Item`) plutôt que lancer une exception pour "non trouvé" quand l'absence est un cas valide.
- Les interfaces vivent dans le namespace applicatif, pas dans `src/Database/`.

### Objet domaine

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

- `id` est nullable avant la persistance.
- Gardez les objets domaine libres d'annotations ORM ou de couplage base de données.

### Adaptateur PDO

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

- Utilisez `DatabaseQueryExecutorInterface` de `src/Database/`, pas PDO brut.
- Tout le SQL reste dans l'adaptateur.
- Castez les valeurs des lignes de base de données en valeurs PHP typées en sortie.
- Préfixe de nom de classe adaptateur : `Pdo` (ex. `PdoItemRepository`).

## Frontière Handler (Contrôleur)

Les handlers restent fins. Leur rôle est de mapper la requête HTTP en entrée de cas d'utilisation, d'appeler le cas d'utilisation et de retourner une réponse.

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

Règles :

- Les handlers ne contiennent pas de logique métier.
- La validation de format et la construction du DTO se font ici.
- Les handlers n'appellent pas directement les dépôts.
- Les handlers reçoivent le cas d'utilisation par injection constructeur, typé à l'interface.

## Organisation du code

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

Groupez par concept domaine, pas par type de couche.

## Câblage du conteneur PSR-11

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

### Tests unitaires de cas d'utilisation

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

### Tests d'intégration d'adaptateurs de dépôt

```bash
docker compose run --rm app composer test:database
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

## Gestion des erreurs

- Lancez des exceptions domaine nommées pour les violations d'invariants métier.
- Mappez les exceptions domaine aux Problem Details à la frontière d'erreur HTTP.
- N'exposez pas les erreurs SQL, les traces de pile ou les identifiants internes dans les réponses d'erreur.

## Non-objectifs

- Active record ou modèles style Eloquent.
- Génération automatique de code depuis OpenAPI ou schémas de base de données.
- CQRS, event sourcing ou patterns saga au premier passage.
- Injection de dépendances par réflexion ou annotation.
- Appels de service locator dans les cas d'utilisation ou objets domaine.

## Documentation associée

- Standards de codage : `docs/development/coding-standards.md`
- Stratégie de test de base de données : `docs/development/test-database-strategy.md`
- Workflow d'échafaudage d'endpoint : `docs/development/endpoint-scaffold.md`
- Guide de démarrage projet client : `docs/development/client-project-start.md`
