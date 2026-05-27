# Épinglage de contenu

Guide d'implémentation de la fonctionnalité d'épinglage d'articles (contenu).
Explique l'épinglage ordonné, la gestion des limites, l'ajout idempotent et la cohérence automatique des positions.

## Vue d'ensemble

- Les utilisateurs épinglent des articles (maximum 10)
- La colonne `position` gère l'ordre (commence à 1)
- Ajout idempotent (200 si déjà présent, 201 si nouveau)
- La position est automatiquement recalculée après dés-épinglage
- L'ordre peut être librement modifié via `PUT /pins/order`

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/pins` | Épingler un article (idempotent) |
| `DELETE` | `/pins/{articleId}` | Dés-épingler |
| `GET` | `/pins` | Liste des épingles (ordonnée) |
| `PUT` | `/pins/order` | Modifier l'ordre des épingles |

## Conception de la base de données

```sql
CREATE TABLE pins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    article_id INTEGER NOT NULL,
    position INTEGER NOT NULL,    -- Commence à 1, entiers consécutifs
    pinned_at TEXT NOT NULL,
    UNIQUE (user_id, article_id), -- Chaque article épinglé une seule fois par utilisateur
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

## Ajout idempotent d'épingle

```php
public function pin(int $userId, int $articleId, string $now): bool
{
    $existing = $this->findPin($userId, $articleId);
    if ($existing !== null) {
        return false;  // Déjà existant : false = 200
    }
    $nextPosition = $this->maxPosition($userId) + 1;
    $this->executor->execute('INSERT INTO pins ...', [$userId, $articleId, $nextPosition, $now]);
    return true;  // Nouveau : true = 201
}
```

Retour `true` → 201 Created, `false` → 200 OK (l'appelant décide du statut).

## Cohérence de position après dés-épinglage

```php
public function unpin(int $userId, int $articleId): bool
{
    $removedPosition = (int) $existing['position'];
    $this->executor->execute('DELETE FROM pins WHERE user_id = ? AND article_id = ?', [...]);
    // Décaler d'un cran vers l'avant les éléments après la position supprimée
    $this->executor->execute(
        'UPDATE pins SET position = position - 1 WHERE user_id = ? AND position > ?',
        [$userId, $removedPosition]
    );
    return true;
}
```

Cohérence automatique pour éviter les vides de position.

## Vérification de la limite

```php
if ($this->repository->countPins($actorId) >= $this->repository->maxPins()) {
    $existing = $this->repository->findPin($actorId, $articleId);
    if ($existing === null) {
        return $this->responseFactory->create([
            'error' => 'pin limit reached',
            'max' => $this->repository->maxPins()
        ], 422);
    }
}
```

La vérification de limite est ignorée lors du ré-épinglage d'un article existant (idempotent).

## Réordonnancement (reorder)

```php
public function reorder(int $userId, array $orderedArticleIds): bool
{
    $currentPins = $this->listPins($userId);
    $currentIds = array_map(fn (array $p) => (int) $p['article_id'], $currentPins);
    sort($currentIds);
    $sortedInput = $orderedArticleIds;
    sort($sortedInput);
    if ($currentIds !== $sortedInput) {
        return false;  // Ne correspond pas à la liste d'épingles actuelle
    }
    foreach ($orderedArticleIds as $position => $articleId) {
        $this->executor->execute(
            'UPDATE pins SET position = ? WHERE user_id = ? AND article_id = ?',
            [$position + 1, $userId, $articleId]
        );
    }
    return true;
}
```

Si `article_ids` ne correspond pas exactement à la liste d'épingles actuelle (dans n'importe quel ordre), retour 422. Seul le réordonnancement est accepté, sans ajout ni suppression.

## Réponse GET /pins

```json
{
  "pins": [
    {"article_id": 3, "title": "Article 3", "position": 1, "pinned_at": "2026-05-21T10:00:00+00:00"},
    {"article_id": 1, "title": "Article 1", "position": 2, "pinned_at": "2026-05-21T09:00:00+00:00"}
  ],
  "count": 2
}
```

Trié par `position` ascendante. Récupéré avec JOIN et `ORDER BY p.position ASC`.

## Libération de la limite (dés-épingler pour permettre un nouvel épinglage)

```
10 épingles → DELETE /pins/{id} → 9 épingles → POST /pins permet d'ajouter
```

La limite est évaluée dynamiquement sur le comptage actuel, donc un nouvel épinglage est immédiatement possible après un dés-épinglage.
