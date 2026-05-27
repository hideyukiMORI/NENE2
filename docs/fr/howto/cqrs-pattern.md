# How-to : Pattern CQRS

> **Référence FT** : FT233 (`NENE2-FT/cqrslog`) — API du pattern CQRS

Montre la ségrégation de responsabilités entre commandes et requêtes (CQRS) : le côté écriture accepte des Commandes et mute le modèle d'écriture ; le côté lecture accepte des Requêtes et lit depuis un modèle de lecture dénormalisé (SQL VIEW). Les deux côtés partagent la même base de données SQLite mais ont des classes de gestionnaires séparées, des objets modèles séparés et aucun état partagé.

---

## Concepts fondamentaux du CQRS

| Concept | Description |
|---------|-------------|
| **Command** | Une intention de changer l'état — `PlaceOrderCommand`, `UpdateOrderStatusCommand` |
| **Query** | Une demande de données — `GetOrderSummaryQuery`, `ListOrderSummariesQuery` |
| **CommandHandler** | Exécute une commande contre le modèle d'écriture (tables normalisées) |
| **QueryHandler** | Exécute une requête contre le modèle de lecture (vue dénormalisée) |
| **Modèle d'écriture** | Tables normalisées optimisées pour les écritures transactionnelles |
| **Modèle de lecture** | Vue dénormalisée optimisée pour la forme de sortie des requêtes |

---

## Routes

| Méthode | Chemin | Côté | Description |
|---------|--------|------|-------------|
| `POST` | `/orders` | Écriture | Passer une nouvelle commande (command) |
| `PATCH` | `/orders/{id}/status` | Écriture | Mettre à jour le statut de la commande (command) |
| `GET` | `/orders` | Lecture | Lister les résumés de commandes (query) |
| `GET` | `/orders/{id}` | Lecture | Obtenir un résumé de commande (query) |

---

## Objets Command (côté écriture)

Les commandes sont des value objects immuables qui portent les données validées dans le gestionnaire :

```php
final readonly class PlaceOrderCommand
{
    /**
     * @param list<array{product: string, quantity: int, unit_price: int}> $items
     */
    public function __construct(
        public string $customer,
        public array  $items,
    ) {
    }
}

final readonly class UpdateOrderStatusCommand
{
    public function __construct(
        public int    $orderId,
        public string $newStatus,
    ) {
    }
}
```

Les commandes ne contiennent aucune logique métier — ce sont des conteneurs typés pour les entrées validées du contrôleur. L'utilisation de `readonly` empêche toute mutation après construction.

---

## Gestionnaire de commandes (modèle d'écriture)

`OrderCommandHandler` possède toutes les mutations. Il écrit dans les tables normalisées :

```php
final readonly class OrderCommandHandler
{
    public function __construct(private DatabaseQueryExecutorInterface $executor) {}

    public function place(PlaceOrderCommand $command, string $now): int
    {
        $orderId = $this->executor->insert(
            'INSERT INTO orders (customer, status, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$command->customer, 'pending', $now, $now],
        );

        foreach ($command->items as $item) {
            $this->executor->insert(
                'INSERT INTO order_items (order_id, product, quantity, unit_price) VALUES (?, ?, ?, ?)',
                [$orderId, $item['product'], $item['quantity'], $item['unit_price']],
            );
        }

        return $orderId;
    }

    public function updateStatus(UpdateOrderStatusCommand $command, string $now): bool
    {
        $affected = $this->executor->execute(
            'UPDATE orders SET status = ?, updated_at = ? WHERE id = ?',
            [$command->newStatus, $now, $command->orderId],
        );

        return $affected > 0;
    }
}
```

Le gestionnaire retourne des valeurs primitives (`int` orderId, `bool` success) — pas des objets du modèle de lecture. Après qu'une commande réussit, le contrôleur ré-interroge le côté lecture pour obtenir la forme de réponse.

---

## Objets Query (côté lecture)

Les requêtes sont des wrappers typés pour les paramètres de requête :

```php
final readonly class GetOrderSummaryQuery
{
    public function __construct(public int $orderId) {}
}

final readonly class ListOrderSummariesQuery
{
    public function __construct(public ?string $status) {}
}
```

Envelopper les paramètres de requête dans des objets rend le contrat du gestionnaire de requêtes explicite et évite l'obsession des primitives dans les signatures de gestionnaires.

---

## Gestionnaire de requêtes (modèle de lecture)

`OrderQueryHandler` lit depuis `order_summary`, une VUE SQL qui dénormalise la jointure au niveau DB :

```php
final readonly class OrderQueryHandler
{
    public function __construct(private DatabaseQueryExecutorInterface $executor) {}

    public function get(GetOrderSummaryQuery $query): ?OrderSummary
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM order_summary WHERE id = ?',
            [$query->orderId],
        );

        return $rows !== [] ? $this->hydrate($rows[0]) : null;
    }

    /** @return list<OrderSummary> */
    public function list(ListOrderSummariesQuery $query): array
    {
        if ($query->status !== null) {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM order_summary WHERE status = ? ORDER BY created_at DESC',
                [$query->status],
            );
        } else {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM order_summary ORDER BY created_at DESC',
                [],
            );
        }

        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): OrderSummary
    {
        return new OrderSummary(
            id:         (int) $row['id'],
            customer:   (string) $row['customer'],
            status:     (string) $row['status'],
            createdAt:  (string) $row['created_at'],
            itemCount:  (int) $row['item_count'],
            totalCents: (int) ($row['total_cents'] ?? 0),
        );
    }
}
```

`OrderSummary` est un DTO du modèle de lecture — il n'est jamais écrit ; il représente uniquement le résultat d'une requête. Le maintenir séparé de toute entité `Order` côté écriture empêche les préoccupations du côté lecture de s'infiltrer dans le modèle d'écriture.

---

## Modèle de lecture : VUE SQL comme projection dénormalisée

Le modèle de lecture est une `VIEW` SQLite qui précalcule la jointure et l'agrégation :

```sql
CREATE VIEW IF NOT EXISTS order_summary AS
SELECT
    o.id,
    o.customer,
    o.status,
    o.created_at,
    COUNT(oi.id)                     AS item_count,
    SUM(oi.quantity * oi.unit_price) AS total_cents
FROM orders o
LEFT JOIN order_items oi ON oi.order_id = o.id
GROUP BY o.id;
```

La vue fournit une surface de requête stable — le gestionnaire de requêtes n'a pas besoin de connaître la jointure normalisée `orders`/`order_items`. Si le modèle d'écriture change sa structure de tables, seule la définition de la vue doit être mise à jour, pas le gestionnaire de requêtes.

`total_cents` stocke les montants monétaires en centimes entiers (pas d'erreurs d'arrondi en virgule flottante). `?? 0` protège contre `NULL` quand aucun article n'existe.

---

## Schéma du modèle d'écriture

```sql
CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    customer   TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'pending',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES orders(id),
    product    TEXT    NOT NULL,
    quantity   INTEGER NOT NULL,
    unit_price INTEGER NOT NULL
);
```

Le modèle d'écriture est normalisé : `orders` + `order_items` dans une relation 1:N. Pas de colonnes calculées — la projection de lecture est dans la vue.

---

## Contrôleur : câbler commandes et requêtes

Après qu'une commande d'écriture réussit, le contrôleur utilise le côté lecture pour construire la réponse :

```php
private function placeOrder(ServerRequestInterface $request): ResponseInterface
{
    // ... valider l'entrée ...
    $orderId = $this->commands->place(new PlaceOrderCommand($customer, $items), $now);

    // Ré-interroger via le modèle de lecture pour obtenir la forme de réponse
    $summary = $this->queries->get(new GetOrderSummaryQuery($orderId));

    return $this->json->create($summary->toArray(), 201);
}
```

Ce pattern "commande puis requête" maintient le côté écriture ignorant de la forme de réponse et assure que la réponse reflète toujours la projection de la vue (y compris les champs calculés comme `total_cents`).

Validation des articles avant la commande :

```php
foreach ($rawItems as $item) {
    if (!is_array($item)) continue;

    $product   = isset($item['product']) && is_string($item['product']) ? trim($item['product']) : '';
    $quantity  = isset($item['quantity']) && is_int($item['quantity']) && $item['quantity'] > 0 ? $item['quantity'] : 0;
    $unitPrice = isset($item['unit_price']) && is_int($item['unit_price']) && $item['unit_price'] >= 0 ? $item['unit_price'] : -1;

    if ($product === '' || $quantity === 0 || $unitPrice < 0) {
        return $this->json->create(['error' => 'each item needs product, quantity>0, unit_price>=0'], 422);
    }
    $items[] = ['product' => $product, 'quantity' => $quantity, 'unit_price' => $unitPrice];
}
```

La vérification stricte `is_int()` sur `quantity` et `unit_price` rejette les floats et chaînes JSON. `unit_price >= 0` autorise zéro (articles gratuits) ; `quantity > 0` requiert au moins un.

---

## Quand utiliser CQRS

CQRS ajoute une charge structurelle. L'utiliser quand :

- Les formes de données lecture et écriture divergent significativement (ex. la liste nécessite des agrégats que le modèle d'écriture ne stocke pas)
- La charge de lecture dépasse largement celle d'écriture et l'on veut les faire monter en charge indépendamment
- Le domaine a des invariants d'écriture complexes (transactions, validation, événements de domaine) qui devraient être isolés des optimisations de lecture
- On construit vers l'event sourcing (CQRS se couple naturellement avec les modèles d'écriture event-sourcés)

Éviter CQRS quand :
- Les formes de lecture et d'écriture sont identiques (un endpoint CRUD simple)
- La base de code est petite et l'indirection dépasse le bénéfice de clarté
- L'équipe ne connaît pas le pattern (introduit une charge cognitive)

---

## Guides associés

- [`event-sourcing.md`](event-sourcing.md) — côté écriture CQRS soutenu par un store d'événements
- [`approval-workflow.md`](approval-workflow.md) — machine à états pour les transitions de statut de commande
- [`transactions.md`](transactions.md) — envelopper les écritures de commandes dans une transaction
- [`batch-api-partial-success.md`](batch-api-partial-success.md) — commandes en lot avec résultats par élément
