# Comment construire un système de vente flash avec NENE2

Ce guide explique comment construire un système de vente flash à durée limitée et quantité contrainte où les utilisateurs peuvent acheter un produit à un prix réduit pendant une fenêtre de vente.

**Essai sur le terrain** : FT140  
**Version NENE2** : ^1.5  
**Sujets couverts** : validation de fenêtre temporelle, comptage de stock avec COUNT(*), contrainte UNIQUE pour un achat par utilisateur, expression `match` pour le statut, test d'attaque cracker

---

## Ce que nous construisons

- `POST /products` — créer un produit
- `POST /sales` — créer une vente flash (product_id, price, quantity, starts_at, ends_at)
- `GET /sales/{saleId}` — voir les détails de la vente avec le compte restant et le statut
- `POST /sales/{saleId}/purchase` — acheter pendant la fenêtre active (un par utilisateur)
- `GET /sales/{saleId}/purchases` — lister tous les acheteurs

---

## Schéma de base de données

```sql
CREATE TABLE flash_sales (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    price      INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    starts_at  TEXT    NOT NULL,
    ends_at    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    CHECK (quantity > 0),
    CHECK (price >= 0),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE purchases (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id      INTEGER NOT NULL,
    user_id      INTEGER NOT NULL,
    purchased_at TEXT    NOT NULL,
    UNIQUE (sale_id, user_id),
    FOREIGN KEY (sale_id) REFERENCES flash_sales(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE (sale_id, user_id)` empêche un utilisateur d'acheter la même vente deux fois, même sous des requêtes concurrentes.

---

## Validation de la fenêtre temporelle

```php
$now = date('c');

if ($now < $sale['starts_at']) {
    return $this->responseFactory->create(['error' => 'sale has not started yet'], 422);
}

if ($now > $sale['ends_at']) {
    return $this->responseFactory->create(['error' => 'sale has ended'], 422);
}
```

Stocker `starts_at` / `ends_at` comme chaînes ISO 8601. La comparaison de chaînes fonctionne correctement pour ISO 8601 car le format est ordonné lexicographiquement.

---

## Comptage de stock avec COUNT(*)

Au lieu de maintenir une colonne `remaining` mutable, compter les achats réels :

```php
public function countPurchases(int $saleId): int
{
    $row = $this->executor->fetchOne(
        'SELECT COUNT(*) as cnt FROM purchases WHERE sale_id = ?',
        [$saleId],
    );

    return isset($row['cnt']) ? (int) $row['cnt'] : 0;
}
```

Puis vérifier :

```php
$purchased = $this->repository->countPurchases($saleId);

if ($purchased >= $sale['quantity']) {
    return $this->responseFactory->create(['error' => 'sold out'], 422);
}
```

`remaining` est dérivé au moment de la lecture : `$sale['quantity'] - $purchased`. Limiter à `max(0, $remaining)` pour éviter l'affichage négatif.

---

## Un achat par utilisateur — contrainte UNIQUE

`UNIQUE (sale_id, user_id)` empêche les doublons au niveau DB. `DatabaseConstraintException` mappe vers 409 :

```php
public function purchase(int $saleId, int $userId, string $now): bool
{
    try {
        $this->executor->execute(
            'INSERT INTO purchases (sale_id, user_id, purchased_at) VALUES (?, ?, ?)',
            [$saleId, $userId, $now],
        );

        return true;
    } catch (DatabaseConstraintException) {
        return false;
    }
}
```

Le gestionnaire retourne 409 quand `purchase()` retourne `false`.

---

## Statut de vente avec expression match

```php
$status = match (true) {
    $now < $sale['starts_at'] => 'upcoming',
    $now > $sale['ends_at']   => 'ended',
    default                   => 'active',
};
```

Trois états : `upcoming`, `active`, `ended`. L'expression `match` est exhaustive car `default` couvre tous les autres cas.

---

## Résultats des tests d'attaque cracker (FT140)

| ID | Attaque | Résultat attendu | Résultat |
|----|---------|------------------|----------|
| ATK-01 | Injection SQL dans le nom du produit | 201 (stocké verbatim) | Pass |
| ATK-02 | Achat sans X-User-Id | 400 | Pass |
| ATK-03 | X-User-Id non numérique | pas 201 | Pass |
| ATK-04 | saleId négatif dans l'URL | pas 201 | Pass |
| ATK-05 | Acheter avant le début de la vente | 422 | Pass |
| ATK-06 | Acheter après la fin de la vente | 422 | Pass |
| ATK-07 | Double achat de la même vente | 409 au deuxième | Pass |
| ATK-08 | Épuiser le stock puis acheter | 422 sold out | Pass |
| ATK-09 | Créer une vente avec quantity=0 | 422 | Pass |
| ATK-10 | Créer une vente avec un prix négatif | 422 | Pass |
| ATK-11 | Achat en tant qu'utilisateur inexistant | 404 | Pass |
| ATK-12 | ends_at avant starts_at | 422 | Pass |

Les 12 tests d'attaque passent.

---

## Pièges courants

| Piège | Correction |
|-------|------------|
| Colonne `remaining` mutable dérive sous la concurrence | Compter depuis la table `purchases`, dériver `remaining` au moment de la lecture |
| Autoriser quantity=0 via l'API | Valider `$quantity > 0` dans le gestionnaire ; aussi `CHECK (quantity > 0)` dans le schéma |
| Le prix négatif passe à travers | Valider `$price >= 0` ; aussi `CHECK (price >= 0)` dans le schéma |
| L'utilisateur achète la même vente deux fois | `UNIQUE (sale_id, user_id)` + `DatabaseConstraintException` → 409 |
| Comparaison temporelle sur des chaînes non-ISO | Utiliser ISO 8601 (ex. `date('c')`) — l'ordre lexicographique est correct |
| `ends_at` inversé avec `starts_at` | Valider `$starts_at < $ends_at` avant INSERT |
