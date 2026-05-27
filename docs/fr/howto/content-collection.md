# Collection de contenu

Guide d'implémentation d'un système de collections curatives d'articles (publiques/privées).
Explique la visibilité, la prévention IDOR (existence non divulguée), l'ajout idempotent et la cohérence de position après suppression.

## Vue d'ensemble

- Les utilisateurs créent des collections nommées (listes)
- Chaque collection peut contenir des articles (maximum 50)
- Indicateur `is_public` : les collections publiques sont visibles par tous
- Les collections privées retournent 404 aux non-propriétaires (pattern d'existence non divulguée)
- L'ajout d'articles est idempotent (200 si déjà présent, 201 si nouveau)
- La position est automatiquement recalculée après suppression d'un élément

## Endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/collections` | Créer une collection |
| `GET` | `/collections/{id}` | Obtenir une collection (publique ou la sienne) |
| `PUT` | `/collections/{id}` | Modifier le nom et la visibilité |
| `DELETE` | `/collections/{id}` | Supprimer une collection |
| `POST` | `/collections/{id}/items` | Ajouter un article (idempotent) |
| `DELETE` | `/collections/{id}/items/{articleId}` | Supprimer un article |

## Conception de la base de données

```sql
CREATE TABLE collections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 0,  -- 0=privé, 1=public
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE collection_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_id INTEGER NOT NULL,
    article_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    added_at TEXT NOT NULL,
    UNIQUE (collection_id, article_id),
    FOREIGN KEY (collection_id) REFERENCES collections(id),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

## Pattern d'existence non divulguée (prévention IDOR)

L'accès à une collection privée retourne **404** et non 403.
Retourner 403 révèlerait l'information "existe mais vous n'avez pas les droits".

```php
if (!$isOwner && !$isPublic) {
    return $this->responseFactory->create(['error' => 'collection not found'], 404);
}
```

## Ajout idempotent d'élément

```php
$existing = $this->repository->findItem($id, $articleId);
if ($existing !== null) {
    return $this->responseFactory->create(['message' => 'already in collection', 'article_id' => $articleId], 200);
}

$count = $this->repository->countItems($id);
if ($count >= CollectionRepository::maxItems()) {
    return $this->responseFactory->create(['error' => 'collection is full', 'max' => 50], 422);
}

$this->repository->addItem($id, $articleId, date('c'));
return $this->responseFactory->create(['message' => 'article added', 'article_id' => $articleId], 201);
```

La vérification de la limite maximale n'est exécutée que lorsque l'article n'est pas encore dans la collection.
Les articles déjà présents n'affectent pas la limite (appel idempotent).

## Cohérence de position après suppression d'élément

```php
public function removeItem(int $collectionId, int $articleId): void
{
    $item = $this->findItem($collectionId, $articleId);
    $removedPosition = (int) $item['position'];
    $this->executor->execute('DELETE FROM collection_items WHERE collection_id = ? AND article_id = ?', ...);
    $this->executor->execute(
        'UPDATE collection_items SET position = position - 1 WHERE collection_id = ? AND position > ?',
        [$collectionId, $removedPosition]
    );
}
```

Les éléments situés après la position supprimée sont décalés d'un cran vers l'avant pour combler le vide.

## Exemple de réponse GET /collections/{id}

```json
{
  "id": 1,
  "user_id": 1,
  "name": "My Reading List",
  "is_public": false,
  "item_count": 2,
  "items": [
    {"article_id": 3, "title": "Article 3", "position": 1, "added_at": "2026-05-21T..."},
    {"article_id": 1, "title": "Article 1", "position": 2, "added_at": "2026-05-21T..."}
  ],
  "created_at": "2026-05-21T...",
  "updated_at": "2026-05-21T..."
}
```

## Pattern de vérification de propriété

La vérification de propriété est effectuée dans tous les endpoints de modification (PUT/DELETE/POST items) :

```php
if ((int) $collection['user_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Pour GET, la réponse 404/200 est déterminée par la combinaison public/privé et propriété — 403 n'est jamais utilisé.
