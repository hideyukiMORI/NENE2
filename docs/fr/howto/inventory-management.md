# How-to : API de gestion des stocks

Ce guide montre comment construire une API de gestion de stocks/inventaire avec des ajustements de stock et un suivi de l'historique avec NENE2.
Pattern démontré par le field trial **inventorylog** (FT220, test d'attaque cracker ATK).

## Fonctionnalités

- Créer des articles d'inventaire avec SKU, nom, prix et quantité initiale (admin uniquement)
- Obtenir les détails d'un article (public)
- Ajuster le stock avec un delta signé (positif = réapprovisionnement, négatif = consommation)
- Détection de stock insuffisant → 409 Conflict
- Historique complet des ajustements

## Schéma

```sql
CREATE TABLE IF NOT EXISTS items (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sku          TEXT    NOT NULL UNIQUE,
    name         TEXT    NOT NULL,
    quantity     INTEGER NOT NULL DEFAULT 0,
    price_cents  INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS stock_logs (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id        INTEGER NOT NULL,
    delta          INTEGER NOT NULL,
    reason         TEXT    NOT NULL DEFAULT '',
    quantity_after INTEGER NOT NULL,
    created_at     TEXT    NOT NULL,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);
```

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/items` | Admin | Créer un article d'inventaire |
| `GET` | `/items/{id}` | Public | Obtenir l'article avec le stock actuel |
| `POST` | `/items/{id}/adjust` | Admin | Ajuster le stock (delta ± N) |
| `GET` | `/items/{id}/history` | Public | Obtenir l'historique des ajustements |

## Pattern d'ajustement de stock

```php
/** @return 'ok'|'not_found'|'insufficient_stock' */
public function adjust(int $id, int $delta, string $reason): string
{
    $item = $this->findById($id);
    if ($item === null) return 'not_found';

    $newQty = (int) $item['quantity'] + $delta;
    if ($newQty < 0) return 'insufficient_stock'; // → 409

    // Mise à jour atomique + log
    $this->pdo->prepare(
        'UPDATE items SET quantity = :qty, updated_at = :now WHERE id = :id'
    )->execute([':qty' => $newQty, ':now' => $now, ':id' => $id]);

    $this->pdo->prepare(
        'INSERT INTO stock_logs (item_id, delta, reason, quantity_after, created_at) VALUES ...'
    )->execute([...]);

    return 'ok';
}
```

## Validation du delta

```php
$delta = $body['delta'] ?? null;
if (!is_int($delta) || $delta === 0 || abs($delta) > self::MAX_QUANTITY) {
    return $this->problem(422, 'validation-failed', 'delta must be a non-zero integer with |delta| ≤ 1000000.');
}
```

## Résultats du test d'attaque cracker ATK (FT220)

- **ATK-01** : Injection SQL dans SKU → bloqué par le pattern `/\A[A-Z0-9\-]{1,32}\z/` (422)
- **ATK-01** : Injection SQL dans l'ID de chemin → bloqué par `ctype_digit()` (404)
- **ATK-02** : Débordement d'entier dans `price_cents` → float rejeté par `is_int()` (422)
- **ATK-03** : ID de chemin surdimensionné → garde `strlen > 18` (404)
- **ATK-04** : Limite drain-to-zero → autorisé (quantité = 0 est valide)
- **ATK-05** : `quantity` surdimensionnée (> 1 000 000) → rejeté (422)
- **ATK-06** : Clé admin incorrecte/vide → 403 (échec fermé)
- **ATK-09** : Attaque de sur-drain → `insufficient_stock` → 409, stock inchangé
- **ATK-10** : `delta` float → rejeté par `is_int()` (422)
- **ATK-11** : Requête sans corps → 400 (corps JSON requis)
- **ATK-12** : Les réponses d'erreur ne contiennent ni SQLSTATE, ni traces de pile, ni chemins internes

## Patterns de sécurité

- **Admin à défaut fermé** : `if ($this->adminKey === '') return false;` avant `hash_equals()`
- **Vérifications strictes `is_int()`** : price_cents, quantity, delta — rejette les floats du JSON
- **`ctype_digit()`** : Validation d'entier sûre contre ReDoS pour les IDs de chemin
- **Pattern SKU** : `/\A[A-Z0-9\-]{1,32}\z/` bloque les tentatives d'injection SQL
- **Opérations atomiques** : mise à jour + insertion de log en séquence (dans une seule connexion)
