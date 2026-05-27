# Comment construire un système de commande invité (Panier → Commande → Articles de commande) avec NENE2

Ce guide explique comment construire un flux de commande e-commerce où les utilisateurs ajoutent des produits à un panier, vérifient le stock et passent une commande qui capture des instantanés de prix dans les articles de commande.

**Essai sur le terrain** : FT139  
**Version NENE2** : ^1.5  
**Sujets couverts** : jointures multi-tables, validation du stock, instantané de prix dans order_items, isolation du panier, calcul du total avec `array_sum`

---

## Ce que nous construisons

- `POST /products` — créer un produit (nom, prix, stock)
- `POST /cart` — ajouter un produit au panier (accumule la quantité si déjà présent)
- `GET /cart` — voir le contenu du panier avec le total (X-User-Id identifie l'utilisateur)
- `DELETE /cart/{productId}` — supprimer un article du panier
- `POST /orders` — passer une commande (valide le stock, décrémente le stock, vide le panier)
- `GET /orders/{orderId}` — voir les détails de la commande avec les articles (propriétaire uniquement)

---

## Schéma de base de données

```sql
CREATE TABLE products (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    price      INTEGER NOT NULL,
    stock      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);

CREATE TABLE cart_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL DEFAULT 1,
    added_at   TEXT    NOT NULL,
    UNIQUE (user_id, product_id),
    FOREIGN KEY (user_id)    REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    total      INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    name       TEXT    NOT NULL,
    price      INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    FOREIGN KEY (order_id)   REFERENCES orders(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

`UNIQUE (user_id, product_id)` sur `cart_items` empêche les lignes dupliquées — ajouter le même produit à nouveau accumule la quantité.

---

## Instantané de prix dans order_items

Quand une commande est passée, le `name` et le `price` actuels du produit sont copiés dans `order_items`. Cela protège les commandes historiques des changements de prix futurs.

```php
/** @param array<int, array{product_id: int, name: string, price: int, quantity: int}> $items */
public function createOrder(int $userId, array $items, int $total, string $now): int
{
    $this->executor->execute(
        'INSERT INTO orders (user_id, total, created_at) VALUES (?, ?, ?)',
        [$userId, $total, $now],
    );

    $orderId = (int) $this->executor->lastInsertId();

    foreach ($items as $item) {
        $this->executor->execute(
            'INSERT INTO order_items (order_id, product_id, name, price, quantity) VALUES (?, ?, ?, ?, ?)',
            [$orderId, $item['product_id'], $item['name'], $item['price'], $item['quantity']],
        );
    }

    return $orderId;
}
```

---

## Accumulation de quantité dans le panier

`UNIQUE (user_id, product_id)` signifie qu'un deuxième `POST /cart` pour le même produit doit faire UPDATE, pas INSERT :

```php
public function addToCart(int $userId, int $productId, int $quantity, string $now): void
{
    $existing = $this->findCartItem($userId, $productId);

    if ($existing !== null) {
        $this->executor->execute(
            'UPDATE cart_items SET quantity = quantity + ? WHERE user_id = ? AND product_id = ?',
            [$quantity, $userId, $productId],
        );
        return;
    }

    $this->executor->execute(
        'INSERT INTO cart_items (user_id, product_id, quantity, added_at) VALUES (?, ?, ?, ?)',
        [$userId, $productId, $quantity, $now],
    );
}
```

---

## Validation du stock avant la passation de commande

Vérifier tous les articles avant de décrémenter tout stock. Le rollback de décrémentation partielle est complexe — valider d'abord, puis agir :

```php
// Valider tous les articles
foreach ($items as $item) {
    $product = $this->repository->findProductById($item['product_id']);

    if ($product === null || $product['stock'] < $item['quantity']) {
        return $this->responseFactory->create([
            'error'      => 'insufficient stock',
            'product_id' => $item['product_id'],
        ], 422);
    }
}

// Décrémenter et créer la commande
foreach ($items as $item) {
    $this->repository->decrementStock($item['product_id'], $item['quantity']);
}

$orderId = $this->repository->createOrder($actorId, $items, $total, date('c'));
$this->repository->clearCart($actorId);
```

---

## Calcul du total du panier

```php
$total = array_sum(array_map(fn(array $i) => $i['price'] * $i['quantity'], $items));
```

Calculé en PHP depuis le résultat de la requête jointe, pas en SQL. Le même calcul est utilisé pour l'aperçu du panier et le total de commande stocké.

---

## Isolation du panier par utilisateur

Les articles du panier sont toujours filtrés par `user_id`. Chaque utilisateur ne voit et ne modifie que son propre panier. Le gestionnaire `GET /cart` retourne une liste vide pour les utilisateurs sans articles — jamais le panier d'un autre utilisateur.

---

## Pièges courants

| Piège | Correction |
|-------|------------|
| Ajouter le même produit deux fois crée des lignes dupliquées | `UNIQUE (user_id, product_id)` + UPDATE en cas de conflit |
| Les changements de prix après la passation corrompent l'historique | Copier `name` et `price` dans `order_items` au moment de la commande |
| Décrémentation partielle du stock en cas d'échec multi-articles | Valider tous les articles d'abord, puis tout décrémenter |
| Retourner le prix actuel du produit dans les détails de commande | Requêter `order_items.price`, pas `products.price` |
| Panier visible entre utilisateurs | Toujours filtrer `cart_items` par `user_id` |
