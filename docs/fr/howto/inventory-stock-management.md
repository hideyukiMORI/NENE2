# How-to : Gestion des stocks d'inventaire

## Vue d'ensemble

Ce guide couvre la construction d'une API de gestion d'inventaire avec NENE2. Les fonctionnalités incluent l'enregistrement d'articles basé sur le SKU, les opérations d'entrée/sortie de stock, la prévention du stock négatif et l'historique des transactions.

**Implémentation de référence** : `../NENE2-FT/inventorylog/`

---

## Conception du schéma

```sql
CREATE TABLE IF NOT EXISTS items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    sku        TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    stock      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS stock_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,   -- 'in' | 'out'
    quantity   INTEGER NOT NULL,
    note       TEXT,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);
```

---

## Table des routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/inventory/items` | Enregistrer un article (SKU + nom) |
| `GET` | `/inventory/items` | Lister tous les articles |
| `GET` | `/inventory/items/{id}` | Obtenir un article par ID |
| `POST` | `/inventory/items/{id}/in` | Entrée de stock (réception) |
| `POST` | `/inventory/items/{id}/out` | Sortie de stock (expédition) |
| `GET` | `/inventory/items/{id}/history` | Historique des transactions |

---

## Validation du SKU

Restreindre le format SKU pour prévenir l'injection et assurer la forme canonique :

```php
if (!preg_match('/\A[A-Z0-9_-]{1,32}\z/', $sku)) {
    return $this->problem(422, 'validation-failed', 'sku must be uppercase alphanumeric (max 32).');
}
```

---

## Opérations de stock

### Entrée de stock

Toujours sûre — juste incrémenter :

```php
$this->pdo->prepare('UPDATE items SET stock = stock + :qty, updated_at = :now WHERE id = :id')
    ->execute([':qty' => $quantity, ':now' => $now, ':id' => $itemId]);
```

### Sortie de stock (avec garde stock insuffisant)

```php
if ((int) $item['stock'] < $quantity) {
    return 'insufficient_stock';   // → 409 Conflict
}
$this->pdo->prepare('UPDATE items SET stock = stock - :qty, updated_at = :now WHERE id = :id')
    ->execute([':qty' => $quantity, ':now' => $now, ':id' => $itemId]);
```

---

## Validation de quantité

Rejeter les quantités non entières et non positives :

```php
$qty = $body['quantity'] ?? null;
if (!is_int($qty) || $qty <= 0) {
    return [0, null, 'quantity must be a positive integer.'];
}
```

Cela capture à la fois `"50"` (chaîne) et `-1` (négatif).

---

## Codes de statut HTTP

| Situation | Statut |
|-----------|--------|
| Article créé | 201 |
| Stock ajouté / réduit | 200 |
| Article / historique trouvé | 200 |
| Champ manquant ou vide | 422 |
| Format SKU invalide | 422 |
| Quantité non entière ou négative | 422 |
| Article non trouvé | 404 |
| SKU dupliqué | 409 |
| Stock insuffisant | 409 |

---

## Notes

- **Mises à jour atomiques** : Utiliser `stock = stock + :qty` et `stock = stock - :qty` en SQL pour maintenir le solde cohérent même sous accès concurrent.
- **Piste d'audit** : Chaque changement de stock écrit une ligne `stock_history` pour la traçabilité.
- **Contrainte souple** : L'application vérifie le stock avant de décrémenter. Pour une exactitude stricte sous concurrence, ajouter une contrainte de colonne `CHECK (stock >= 0)` dans la DB ou utiliser des transactions avec verrouillage de ligne.
