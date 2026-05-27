# Transactions de base de données

Utilisez `DatabaseTransactionManagerInterface::transactional()` pour les opérations atomiques multi-étapes.
Si une étape lève une exception, toutes les modifications dans le callback sont automatiquement annulées.

## La règle critique : instancier les repositories à l'intérieur du callback

> **Avertissement :** Les repositories injectés au moment de la construction utilisent une connexion PDO *différente* et s'exécutent **en dehors de la transaction**. Les annulations ne défont pas leurs modifications.

`PdoConnectionFactory` crée une nouvelle connexion à chaque appel. Quand `transactional()` ouvre une transaction, il l'ouvre sur une *nouvelle* connexion. Tout repository créé avant cet appel (par exemple, injecté via DI dans le constructeur) détient une connexion différente — une qui ne fait pas partie de la transaction.

### Pattern correct

```php
final class OrderService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($executor) use ($items): int {
            // Instancier les repositories À L'INTÉRIEUR du callback avec l'exécuteur scoped à la tx
            $inventory = new InventoryRepository($executor);
            $orders    = new OrderRepository($executor);

            foreach ($items as $item) {
                // lève InsufficientStockException → annulation déclenchée automatiquement
                $inventory->decrement($item['product_id'], $item['quantity']);
            }

            return $orders->create($items);
        });
    }
}
```

### Pattern incorrect (modifications NON annulées)

```php
// Ces repos détiennent une connexion différente — pas partie de la transaction
public function __construct(
    private readonly InventoryRepository $inventory,
    private readonly OrderRepository $orders,
    private readonly DatabaseTransactionManagerInterface $tx,
) {}

public function placeOrder(array $items): int
{
    return $this->tx->transactional(function ($executor) use ($items): int {
        foreach ($items as $item) {
            $this->inventory->decrement(...); // ← connexion différente, hors transaction !
        }
        return $this->orders->create($items); // ← même problème
        // Si une exception est levée, les modifications de $this->inventory ne sont PAS défaites
    });
}
```

C'est un échec silencieux : le code compile, PHPStan ne peut pas le détecter, et les tests peuvent passer sauf s'ils vérifient spécifiquement le comportement d'annulation.

---

## Comportement d'annulation

`transactional()` capture `Throwable`, annule, puis relève. L'appelant reçoit l'exception originale.

```php
try {
    $this->orderService->placeOrder($items);
} catch (InsufficientStockException $e) {
    // Toutes les décrémentations d'inventaire de cet appel sont défaites
    // Aucune commande n'a été créée
}
```

---

## Pattern de pré-validation + opération atomique

Le pattern d'échec rapide ci-dessus s'arrête au premier article en rupture de stock, laissant le client ignorant des autres échecs.
Quand l'UX importe, collectez d'abord toutes les erreurs, puis exécutez atomiquement :

```php
public function placeOrder(array $items): int
{
    // Phase 1 : valider tous les articles hors transaction (lecture seule)
    $errors = [];
    foreach ($items as $item) {
        $stock = $this->getStockSnapshot($item['product_id']);
        if ($stock < $item['quantity']) {
            $errors[] = [
                'product_id' => $item['product_id'],
                'requested'  => $item['quantity'],
                'available'  => $stock,
            ];
        }
    }

    if ($errors !== []) {
        throw new InsufficientStockException($errors);
    }

    // Phase 2 : décrémentation atomique — utiliser quand même l'exécuteur scoped à la tx à l'intérieur
    return $this->tx->transactional(function ($executor) use ($items): int {
        $inventory = new InventoryRepository($executor);
        $orders    = new OrderRepository($executor);

        foreach ($items as $item) {
            // La contrainte CHECK en DB est le filet de sécurité final pour les courses
            $inventory->decrement($item['product_id'], $item['quantity']);
        }

        return $orders->create($items);
    });
}
```

**Compromis :**
- La lecture en Phase 1 ne fait pas partie de la transaction, donc une requête concurrente peut épuiser le stock entre la Phase 1 et la Phase 2. La contrainte `CHECK (stock >= 0)` de la base de données (ou `decrement()` levant une exception sur un stock négatif) capture cette race.
- Pour la plupart des applications, c'est acceptable. Pour une exactitude stricte, utilisez `SELECT ... FOR UPDATE` ou un niveau d'isolation sérialisable (non disponible dans SQLite).

---

## Tester la correction de l'annulation

Vérifiez toujours que l'inventaire est inchangé après une commande échouée :

```php
$this->inventory->seed(1, 'Widget', 10);
$this->inventory->seed(2, 'Gadget', 1);

// La décrémentation de Widget réussirait, mais Gadget échoue → les deux doivent être annulés
$res = $this->post('/orders', ['items' => [
    ['product_id' => 1, 'quantity' => 3],
    ['product_id' => 2, 'quantity' => 5], // seulement 1 en stock
]]);

assertSame(422, $res->getStatusCode());
assertSame(10, $this->inventory->getStock(1), 'Widget doit être annulé à 10');
assertSame(0, $this->orders->count(), 'Aucune commande créée');
```

Les tests unitaires qui mockent les repositories ne peuvent pas détecter cette classe de bugs — seuls les tests d'intégration qui partagent la même connexion SQLite/MySQL peuvent vérifier la correction de l'annulation.

---

## Laravel vs NENE2

Le `DB::transaction()` de Laravel fonctionne avec les modèles injectés parce qu'il partage transparemment une seule connexion dans toute la requête. Le `PdoConnectionFactory` de NENE2 retourne une nouvelle connexion à chaque appel — un choix de conception délibéré pour la testabilité et le contrôle explicite des connexions. La conséquence est que le pattern d'exécuteur scoped au callback est **requis** dans NENE2.
