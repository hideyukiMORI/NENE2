# Commentaires en fil

Implémenter des fils de commentaires auto-référencés avec limites de profondeur et suppression douce.

## Vue d'ensemble

Un système de commentaires en fil a une table qui se référence elle-même. Chaque commentaire connaît son `parent_id` (null pour le niveau supérieur), sa `depth` (base 0), et son `status`. Les réponses s'imbriquent dans leur parent dans l'arbre de réponse.

## Schéma de base de données

```sql
CREATE TABLE comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id     INTEGER NOT NULL,
    parent_id   INTEGER,
    author_name TEXT    NOT NULL,
    body        TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'published',
    depth       INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (post_id)   REFERENCES posts(id),
    FOREIGN KEY (parent_id) REFERENCES comments(id)
);
```

`depth` est dénormalisé dans la ligne pour éviter les requêtes ancestrales récursives à chaque insertion.

## Profondeur maximale

Imposer une limite de profondeur au moment de l'écriture :

```php
public const int MAX_DEPTH = 3;

public function canHaveReplies(): bool
{
    return $this->depth < self::MAX_DEPTH;
}
```

Dans le handler de route, vérifier avant d'insérer :

```php
if (!$parent->canHaveReplies()) {
    return $this->problems->create($request, 'unprocessable-entity', 'Maximum comment depth reached.', 422, '');
}

$comment = $this->repo->addComment(
    $parent->postId, $parentId, $authorName, $body, $parent->depth + 1, $now,
);
```

## Suppression douce

La suppression douce remplace le body par `[deleted]` et définit `status = 'deleted'`. Les enfants sont préservés :

```php
public function softDelete(int $id): void
{
    $this->executor->execute(
        "UPDATE comments SET status = 'deleted', body = '[deleted]' WHERE id = ?",
        [$id],
    );
}
```

La récupération d'arbre retourne les commentaires supprimés avec des bodies `[deleted]` pour que la structure du fil reste cohérente — les lecteurs voient un placeholder où était le commentaire supprimé, et ses enfants sont toujours visibles.

Tenter de répondre à un commentaire supprimé retourne 409 :

```php
if ($parent->isDeleted()) {
    return $this->problems->create($request, 'conflict', 'Cannot reply to a deleted comment.', 409, '');
}
```

## Construire l'arbre de commentaires sans N+1

Charger tous les commentaires pour un post en une seule requête ordonnée par ID (les parents ont toujours des IDs inférieurs à leurs enfants). Puis assembler l'arbre en PHP en deux passes :

```php
// Passe 1 : construire la carte de lignes brutes et la liste d'adjacence d'IDs enfants
foreach ($rows as $row) {
    $rowMap[(int) $row['id']]   = $row;
    $childIds[(int) $row['id']] = [];
}

foreach ($rowMap as $id => $row) {
    $parentId = isset($row['parent_id']) ? (int) $row['parent_id'] : null;
    if ($parentId === null) {
        $roots[] = $id;
    } elseif (isset($childIds[$parentId])) {
        $childIds[$parentId][] = $id;
    }
}

// Passe 2 : construire récursivement les value objects Comment depuis les racines
return $this->buildTree($roots, $rowMap, $childIds);
```

Garder les lignes brutes et les listes d'IDs enfants `int[]` séparées des value objects `Comment` évite la confusion de type PHPStan quand on travaille avec des classes `readonly`.

## Séparer les données de lignes des value objects

Lors de l'assemblage récursif d'un arbre de value objects readonly, PHPStan a besoin de frontières de type propres. Le pattern qui fonctionne :

1. **Passe 1** — construire `array<int, array<string, mixed>> $rowMap` et `array<int, int[]> $childIds` depuis les lignes brutes. Pas encore de value objects.
2. **Passe 2** — `buildTree()` prend uniquement des IDs `int[]` et les deux cartes, récurse, et hydrate des objets `Comment` avec des tableaux d'enfants entièrement assemblés.

Cela évite de mélanger des objets `Comment` et des IDs `int` dans le même tableau, ce qui causerait un type union que PHPStan ne peut pas réduire.

## Résumé des routes

| Méthode | Chemin | Description |
|---|---|---|
| `POST` | `/posts` | Créer un post |
| `GET` | `/posts/{id}` | Obtenir un post |
| `POST` | `/posts/{id}/comments` | Ajouter un commentaire de premier niveau |
| `GET` | `/posts/{id}/comments` | Obtenir l'arbre de commentaires |
| `POST` | `/comments/{id}/replies` | Répondre à un commentaire |
| `DELETE` | `/comments/{id}` | Suppression douce d'un commentaire |

## Notes de conception

- `depth` est stocké dans la ligne (dénormalisé) pour éviter les requêtes ancestrales récursives à chaque insertion.
- `ORDER BY id ASC` garantit que les parents apparaissent avant leurs enfants lors du chargement de la liste plate.
- La suppression douce préserve la structure du fil — la suppression physique orpheliserait les commentaires enfants.
- Répondre à un commentaire supprimé est bloqué (409) pour prévenir les fils fantômes.
