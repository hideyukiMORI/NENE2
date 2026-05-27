# How-to : Pattern de portée de transaction

> **Référence FT** : FT253 (`NENE2-FT/txlog`) — Frontières de transaction de base de données : commande atomique avec gestion d'inventaire

Démontre le pattern correct pour `DatabaseTransactionManagerInterface::transactional()`
dans NENE2. Les repositories doivent être instanciés **à l'intérieur** du callback avec
l'exécuteur scoped à la transaction — les repositories pré-injectés opèrent sur une connexion
différente et leurs écritures ne sont **pas** annulées si la transaction échoue.

---

## Routes

| Méthode | Chemin    | Description                                                  |
|---------|-----------|--------------------------------------------------------------|
| `POST`  | `/orders` | Passer une commande (déduction d'inventaire atomique + création de commande) |

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS inventory (
    product_id   INTEGER PRIMARY KEY,
    product_name TEXT    NOT NULL,
    stock        INTEGER NOT NULL CHECK (stock >= 0)
);

CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    status     TEXT    NOT NULL DEFAULT 'placed',
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES orders(id),
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL
);
```

`CHECK(stock >= 0)` est un filet de sécurité au niveau DB qui empêche le stock de devenir
négatif même si la logique applicative a un bug. L'application valide également le stock avant
de décrémenter, donc en fonctionnement normal la contrainte n'est jamais déclenchée.

---

## Le piège de la portée de l'exécuteur

`DatabaseTransactionManagerInterface::transactional()` ouvre une transaction et passe un
**exécuteur scoped à la transaction** au callback. Les écritures effectuées via cet exécuteur
font partie de la transaction et sont annulées si une exception est levée.

**Incorrect : repository injecté avant la transaction**

```php
final class OrderService
{
    public function __construct(
        private readonly InventoryRepository $inventory, // utilise l'exécuteur externe
        private readonly OrderRepository     $orders,    // utilise l'exécuteur externe
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($txExecutor) use ($items): int {
            // BUG : $this->inventory et $this->orders utilisent l'exécuteur externe,
            // pas $txExecutor. Leurs écritures sont sur une connexion différente.
            // Si la transaction est annulée, leurs modifications ne sont PAS défaites.
            foreach ($items as $item) {
                $this->inventory->decrement($item['product_id'], $item['quantity']);
            }
            return $this->orders->create($items);
        });
    }
}
```

**Correct : repositories créés à l'intérieur du callback**

```php
final class OrderService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
        // Injecter uniquement le gestionnaire de transaction, pas les repositories.
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($executor) use ($items): int {
            // Les repositories sont instanciés avec l'exécuteur scoped à la transaction.
            // Toutes les lectures et écritures passent par la même connexion, dans la même transaction.
            $inventory = new InventoryRepository($executor);
            $orders    = new OrderRepository($executor);

            foreach ($items as $item) {
                // Lève InsufficientStockException → déclenche l'annulation
                $inventory->decrement($item['product_id'], $item['quantity']);
            }

            return $orders->create($items);
        });
    }
}
```

L'`$executor` scoped à la transaction passé par `transactional()` partage la même connexion
PDO et le même contexte de transaction. Créer des repositories à l'intérieur du callback avec
cet exécuteur garantit que toutes leurs écritures participent à la même transaction atomique.

---

## Décrémentation d'inventaire avec vérification au niveau applicatif

```php
public function decrement(int $productId, int $qty): void
{
    $current = $this->getStock($productId);
    if ($current < $qty) {
        throw new InsufficientStockException($productId, $qty, $current);
    }
    $this->executor->execute(
        'UPDATE inventory SET stock = stock - ? WHERE product_id = ?',
        [$qty, $productId],
    );
}
```

Le pattern lire-puis-écrire (`getStock()` + `UPDATE`) n'est pas atomique par lui-même —
une requête concurrente pourrait lire le même stock et les deux réussir. En production,
enveloppez ceci dans un `SELECT ... FOR UPDATE` (MySQL) ou utilisez `UPDATE ... WHERE stock >= ?`
comme vérification optimiste :

```sql
UPDATE inventory SET stock = stock - ? WHERE product_id = ? AND stock >= ?
-- puis vérifier que les lignes affectées == 1 ; si 0, lever InsufficientStockException
```

SQLite ne supporte pas `SELECT FOR UPDATE`, donc la contrainte `CHECK(stock >= 0)` capture les
courses concurrentes au niveau DB — la deuxième mise à jour lèverait une violation de contrainte,
qui se propage comme une exception et déclenche l'annulation.

---

## Atomicité multi-articles : l'échec partiel déclenche l'annulation complète

```php
foreach ($items as $item) {
    $inventory->decrement($item['product_id'], $item['quantity']); // peut lever
}
return $orders->create($items); // atteint uniquement si toutes les décrémentations réussissent
```

Si le **premier** article échoue, aucune modification d'inventaire n'est effectuée. Si le
**dernier** article échoue, toutes les décrémentations précédentes sont annulées. La suite de
tests vérifie les deux cas :

```php
// Widget : 10 en stock ; Gadget : 1 en stock. Commande [Widget×3, Gadget×5] échoue sur Gadget.
$this->assertSame(10, $inventory->getStock(1)); // Widget restauré à 10
$this->assertSame(0, $orders->count());          // Aucune commande créée
```

---

## Exception → annulation → mapping 422

L'exception levée à l'intérieur de `transactional()` se propage à l'appelant :

```php
try {
    $orderId = $this->service->placeOrder($items);
    return $this->json->create(['order_id' => $orderId, 'status' => 'placed'], 201);
} catch (InsufficientStockException $e) {
    return $this->problems->create(
        $request,
        'insufficient-stock',
        'Insufficient stock.',
        422,
        $e->getMessage(),
    );
}
```

`transactional()` capture l'exception, appelle `ROLLBACK`, puis la relève. L'appelant
capture l'exception relevée et la mappe à une réponse Problem Details.

---

## Validation des entrées : `is_int` strict pour les quantités

```php
foreach ($body['items'] as $i => $item) {
    if (
        !is_array($item) ||
        !isset($item['product_id'], $item['quantity']) ||
        !is_int($item['product_id']) ||
        !is_int($item['quantity']) ||
        $item['quantity'] < 1
    ) {
        return $this->problems->create(
            $request,
            'validation-failed',
            'Validation failed.',
            422,
            null,
            ['errors' => [['field' => "items.{$i}", 'code' => 'invalid', ...]]],
        );
    }
}
```

`is_int()` rejette les flottants JSON (`1.0`) et les chaînes (`"3"`). Le décodeur JSON de PHP
convertit les valeurs entières JSON en `int` PHP, donc `is_int()` fonctionne correctement pour
l'entrée JSON. Utiliser `is_numeric()` uniquement pour les paramètres de chaîne de requête (qui
sont toujours des chaînes).

---

## Résumé d'utilisation de `transactional()`

| À faire | À ne pas faire |
|---------|----------------|
| Créer des repositories à l'intérieur du callback | Injecter des repositories à la construction de la classe |
| Passer `$executor` du callback aux nouveaux repositories | Utiliser `$this->executor` injecté dans le constructeur |
| Laisser les exceptions se propager pour déclencher l'annulation | Attraper les exceptions silencieusement à l'intérieur du callback |
| Retourner une valeur depuis le callback | Retourner `void` quand vous avez besoin de l'ID inséré |

---

## Howtos liés

- [`transactions.md`](transactions.md) — référence de l'API `DatabaseTransactionManagerInterface`
- [`use-transactions.md`](use-transactions.md) — ajouter des transactions à un endpoint existant
- [`budget-tracking.md`](budget-tracking.md) — transfert de fonds avec transaction (mise à jour du solde de deux comptes)
- [`order-management.md`](order-management.md) — cycle de vie complet d'une commande (création, statut, annulation)
- [`optimistic-locking.md`](optimistic-locking.md) — prévention des conditions de course sans transactions
