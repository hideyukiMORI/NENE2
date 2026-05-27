# How-to : API de collections (Listes curatives utilisateur)

> **Référence FT** : FT299 (`NENE2-FT/collectionlog`) — Collections d'articles curatives utilisateur : visibilité is_public/private (404 aux non-propriétaires pour les collections privées), déduplication UNIQUE(collection_id, article_id), ordre par position, accès en écriture propriétaire uniquement, 20 tests / 34 assertions PASS.

Ce guide montre comment construire une API de collections curatives où les utilisateurs créent des listes nommées, y ajoutent des articles et contrôlent la visibilité public/privé.

## Schéma

```sql
CREATE TABLE collections (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    name       TEXT    NOT NULL,
    is_public  INTEGER NOT NULL DEFAULT 0,  -- 0=privé, 1=public
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE collection_items (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_id INTEGER NOT NULL,
    article_id    INTEGER NOT NULL,
    position      INTEGER NOT NULL,
    added_at      TEXT    NOT NULL,
    UNIQUE (collection_id, article_id),
    FOREIGN KEY (collection_id) REFERENCES collections(id),
    FOREIGN KEY (article_id)    REFERENCES articles(id)
);
```

`UNIQUE(collection_id, article_id)` empêche le même article d'apparaître deux fois dans une collection. `position` permet l'affichage ordonné.

## Endpoints

| Méthode | Chemin | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/collections` | `X-User-Id` | Créer une collection |
| `GET` | `/collections/{id}` | `X-User-Id` | Obtenir une collection (vérification de visibilité) |
| `PUT` | `/collections/{id}` | `X-User-Id` (propriétaire) | Mettre à jour le nom/visibilité |
| `DELETE` | `/collections/{id}` | `X-User-Id` (propriétaire) | Supprimer une collection |
| `POST` | `/collections/{id}/items` | `X-User-Id` (propriétaire) | Ajouter un article |
| `DELETE` | `/collections/{id}/items/{articleId}` | `X-User-Id` (propriétaire) | Supprimer un article |

## Visibilité — 404 pour les collections privées

```php
$isOwner  = (int) $collection['user_id'] === $actorId;
$isPublic = (bool) $collection['is_public'];

if (!$isOwner && !$isPublic) {
    return $this->responseFactory->create(['error' => 'collection not found'], 404);
}
```

Les non-propriétaires qui essaient d'accéder à une collection privée reçoivent 404 — pas 403. Cela empêche de divulguer l'existence des collections privées.

## Accès en écriture propriétaire uniquement

```php
if ((int) $collection['user_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Les opérations d'ajout, suppression, mise à jour et suppression nécessitent que l'acteur soit le propriétaire de la collection. Contrairement à la visibilité, les échecs d'accès en écriture retournent 403 (l'existence de la collection est déjà connue à ce stade).

## UNIQUE(collection_id, article_id) — Déduplication

La contrainte DB empêche le même article d'apparaître deux fois dans une collection. L'application vérifie les doublons avant l'insertion :

```php
// Le Repository vérifie findItem() avant addItem()
if ($this->repository->findItem($id, $articleId) !== null) {
    return $this->responseFactory->create(['error' => 'article already in collection'], 409);
}
$this->repository->addItem($id, $articleId, date('c'));
```

## is_public comme entier booléen

```php
$isPublic = isset($body['is_public']) && $body['is_public'] === true;
```

`is_public` est stocké comme INTEGER (0/1) dans SQLite. En lecture : `(bool) $collection['is_public']`. En écriture : vérification stricte `=== true` empêche la chaîne `"true"` d'activer l'accès public.

## Forme de la réponse

```php
private function formatCollection(array $collection, array $items): array
{
    return [
        'id'         => (int)    $collection['id'],
        'user_id'    => (int)    $collection['user_id'],
        'name'       => (string) $collection['name'],
        'is_public'  => (bool)   $collection['is_public'],
        'item_count' => count($items),
        'items'      => array_map(fn($item) => [
            'article_id' => (int)    $item['article_id'],
            'title'      => (string) $item['article_title'],
            'position'   => (int)    $item['position'],
            'added_at'   => (string) $item['added_at'],
        ], $items),
        'created_at' => (string) $collection['created_at'],
        'updated_at' => (string) $collection['updated_at'],
    ];
}
```

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Retourner 403 pour l'accès à une collection privée | Révèle l'existence de la collection aux non-propriétaires (divulgation d'information) |
| Permettre à n'importe quel utilisateur d'ajouter des éléments à n'importe quelle collection | Les non-propriétaires injectent du contenu dans les collections des autres |
| Pas de `UNIQUE(collection_id, article_id)` | Même article ajouté deux fois ; entrées en doublon déroutantes |
| Accepter la chaîne `"true"` pour `is_public` | Confusion de type : toute chaîne est vraie en comparaison lâche |
| Pas de champ position | Les éléments apparaissent toujours dans l'ordre d'insertion ; pas de réordonnancement possible |
| DELETE collection sans vérification de propriété | N'importe quel utilisateur supprime n'importe quelle collection |
| Exposer `item_count` sans inclure les éléments | Révèle la taille de la collection même aux non-propriétaires des collections privées |
