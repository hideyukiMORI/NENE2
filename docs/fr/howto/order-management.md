# How-to : API de gestion des commandes

> **Référence FT** : FT274 (`NENE2-FT/orderlog`) — Gestion des commandes : articles validés par SKU, calcul automatique de total_cents, cycle de vie du statut (pending→confirmed→shipped→delivered→cancelled), IDOR → 404, override admin, détection de conflit d'annulation, 36 tests PASS.
>
> Également validé dans FT215 (`NENE2-FT/orderlog` précurseur) — même pattern, implémentation antérieure.

Ce guide montre comment créer une API de gestion de commandes multi-articles avec NENE2.

## Fonctionnalités

- Créer des commandes avec des articles (SKU + quantité + prix unitaire)
- Calcul automatique du total depuis les articles
- Cycle de vie du statut : `pending → confirmed → shipped → delivered → cancelled`
- Protection IDOR scopée par utilisateur (retourne 404, pas 403, pour cacher l'existence)
- Override admin pour les opérations inter-utilisateurs
- Annulation atomique avec détection de conflit (impossible d'annuler `cancelled` ou `delivered`)

## Schéma

```sql
CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending',
    total_cents INTEGER NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
CREATE TABLE IF NOT EXISTS order_items (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id    INTEGER NOT NULL,
    sku         TEXT    NOT NULL,
    quantity    INTEGER NOT NULL,
    unit_cents  INTEGER NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_orders_user ON orders (user_id, id DESC);
```

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/orders` | Créer une commande avec articles |
| `GET` | `/orders/{id}` | Obtenir une commande avec articles (propriétaire ou admin) |
| `POST` | `/orders/{id}/cancel` | Annuler une commande (propriétaire ou admin) |
| `GET` | `/users/{userId}/orders` | Lister les commandes d'un utilisateur (soi-même ou admin) |

## Validation des articles

```php
/** SKU : alphanumériques majuscules et tirets, 1–32 caractères */
private const string SKU_PATTERN = '/\A[A-Z0-9\-]{1,32}\z/';

// Par article :
// - sku : doit correspondre à SKU_PATTERN
// - quantity : entier 1–9999
// - unit_cents : entier non négatif
// Maximum 50 articles par commande
```

## Pattern Repository

```php
/**
 * @param list<array{sku: string, quantity: int, unit_cents: int}> $items
 * @return array<string, mixed>
 */
public function create(int $userId, string $note, array $items): array
{
    $totalCents = 0;
    foreach ($items as $item) {
        $totalCents += $item['quantity'] * $item['unit_cents'];
    }
    // INSERT order, puis INSERT items, retourne findById()
}

/** @return 'not_found'|'not_cancellable'|'ok' */
public function cancel(int $id, int $userId, bool $isAdmin): string
{
    // Retourne 'not_found' pour mauvais utilisateur (protection IDOR)
    // Retourne 'not_cancellable' pour commandes annulées/livrées
}
```

## Protection IDOR

Les endpoints scopés par utilisateur retournent `404` (pas `403`) quand un utilisateur accède à la ressource d'un autre :

```php
// GET /orders/{id}
if (!$isAdmin && (int) $order['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Order not found.');
}

// GET /users/{userId}/orders
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## Annulation avec expression match

```php
$result = $this->repo->cancel($id, $uid ?? 0, $isAdmin);

return match ($result) {
    'not_found'       => $this->problem(404, 'not-found', 'Order not found.'),
    'not_cancellable' => $this->problem(409, 'conflict', 'Order cannot be cancelled.'),
    default           => $this->json(['message' => 'Order cancelled.']),
};
```

## Patterns de sécurité

- **Admin fail-closed** : `if ($this->adminKey === '') return false;` avant `hash_equals()`
- **`ctype_digit()`** : validation d'entier résistante aux ReDoS pour les IDs dans les chemins et en-têtes
- **`is_int()`** : vérification de type stricte — rejette les floats (ex. `1.5`) passés en JSON
- **Garde max articles** : limite à 50 articles pour prévenir les payloads surdimensionnés

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Stocker le prix en FLOAT | Erreurs d'arrondi en virgule flottante dans les totaux (utiliser des centimes INTEGER) |
| Accepter des chaînes SKU libres | Surface d'injection ; allowlist avec regex (ex. `[A-Z0-9\-]{1,32}`) |
| Pas de limite max d'articles | L'attaquant envoie un tableau de 10 000 articles causant une boucle INSERT lente |
| Calculer le total côté client | Le client peut envoyer n'importe quel total ; toujours dériver de `quantity × unit_cents` |
| Retourner 403 sur accès commande mauvais utilisateur | Révèle que la commande existe ; utiliser 404 pour cacher la propriété |
| Permettre l'annulation des commandes livrées | Les commandes traitées doivent être immuables ; utiliser une machine à états |
| Omettre `ON DELETE CASCADE` sur order_items | Supprimer une commande laisse des articles orphelins |
