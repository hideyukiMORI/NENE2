# Gestion de liste de souhaits

Guide d'implémentation d'une liste de souhaits avec priorité et notes.
Couvre le pattern d'existence non-divulguée, l'ajout idempotent et les paramètres de chemin multiples.

## Vue d'ensemble

- Les utilisateurs créent des listes de souhaits nommées (publiques/privées)
- Chaque liste de souhaits peut contenir des produits (`priority` : high/medium/low, `note` optionnelle)
- Les listes de souhaits privées retournent 404 aux non-propriétaires (pattern d'existence non-divulguée)
- L'ajout de produit est idempotent (200 si existant, 201 si nouveau)
- Pas d'ordre (pas de gestion de position) — principale différence avec la collection de contenu (FT149)

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/wishlists` | Créer une liste de souhaits |
| `GET` | `/wishlists/{id}` | Obtenir une liste de souhaits (publique ou la sienne) |
| `PUT` | `/wishlists/{id}` | Modifier le nom et la visibilité |
| `DELETE` | `/wishlists/{id}` | Supprimer la liste de souhaits |
| `POST` | `/wishlists/{id}/items` | Ajouter un produit (idempotent) |
| `DELETE` | `/wishlists/{id}/items/{productId}` | Supprimer un produit |

## Conception de la base de données

```sql
CREATE TABLE wishlists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE wishlist_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    wishlist_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    priority TEXT NOT NULL DEFAULT 'medium',
    note TEXT,
    added_at TEXT NOT NULL,
    UNIQUE (wishlist_id, product_id),
    CHECK (priority IN ('high', 'medium', 'low')),
    FOREIGN KEY (wishlist_id) REFERENCES wishlists(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

Contrairement à la collection de contenu (FT149), il n'y a pas de colonne `position`.
`UNIQUE (wishlist_id, product_id)` est la défense au niveau DB pour l'ajout idempotent.

## Pattern d'existence non-divulguée

```php
$isOwner = $actorId !== null && (int) $wishlist['user_id'] === $actorId;
$isPublic = (bool) $wishlist['is_public'];

if (!$isOwner && !$isPublic) {
    return $this->responseFactory->create(['error' => 'wishlist not found'], 404);
}
```

Seul GET retourne 404. PUT/DELETE/POST items retournent 403 pour informer le propriétaire d'une autorisation insuffisante.

## Ajout idempotent d'article

```php
$existing = $this->repository->findItem($id, $productId);
if ($existing !== null) {
    return $this->responseFactory->create([
        'message' => 'already in wishlist',
        'product_id' => $productId,
        'priority' => $existing['priority'],
        'note' => $existing['note'],
    ], 200);
}
$now = date('c');
$this->repository->addItem($id, $productId, $priority, $note, $now);
return $this->responseFactory->create([...], 201);
```

## Validation de la priorité (repli vers la valeur par défaut pour les valeurs invalides)

```php
private const array VALID_PRIORITIES = ['high', 'medium', 'low'];

$priority = isset($body['priority']) && is_string($body['priority'])
    && in_array($body['priority'], self::VALID_PRIORITIES, true)
    ? $body['priority']
    : 'medium';
```

Les valeurs de priorité invalides se replient sur `'medium'` au lieu de retourner une erreur.
Cela permet aux clients de gérer en toute sécurité les valeurs de priorité inconnues pour la compatibilité ascendante.

## Exemple de réponse GET /wishlists/{id}

```json
{
  "id": 1,
  "user_id": 1,
  "name": "Birthday Wishlist",
  "is_public": true,
  "item_count": 2,
  "items": [
    {
      "product_id": 3,
      "product_name": "Wireless Headphones",
      "priority": "high",
      "note": "Black color preferred",
      "added_at": "2026-05-21T..."
    },
    {
      "product_id": 1,
      "product_name": "Coffee Mug",
      "priority": "low",
      "note": null,
      "added_at": "2026-05-21T..."
    }
  ],
  "created_at": "2026-05-21T...",
  "updated_at": "2026-05-21T..."
}
```

## Différences entre collection et liste de souhaits

| Aspect | Collection (FT149) | Liste de souhaits (FT151) |
|---|---|---|
| Ordre | Gestion de position | Aucun (ordre d'ajout) |
| Métadonnées d'article | Aucune | priority + note |
| Limite | 50 articles | Aucune |
| Cas d'usage | Liste de lecture, curation | Liste d'envies, registre de cadeaux |

## Pattern de vérification de propriété

```php
if ((int) $wishlist['user_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Le même pattern est utilisé pour PUT/DELETE/POST items.
