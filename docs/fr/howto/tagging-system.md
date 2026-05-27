# Système de tagging (M:N)

Attacher des tags à des posts en utilisant une table de jointure many-to-many, avec remplacement atomique des tags et récupération des tags sans problème N+1.

## Vue d'ensemble

Un système de tagging a trois tables : `posts`, `tags`, et `post_tags` (la table de jointure). Les posts et les tags ont une relation M:N — un post a plusieurs tags, un tag appartient à plusieurs posts.

## Schéma de base de données

```sql
CREATE TABLE posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);

CREATE TABLE tags (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE post_tags (
    post_id INTEGER NOT NULL,
    tag_id  INTEGER NOT NULL,
    PRIMARY KEY (post_id, tag_id),
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (tag_id)  REFERENCES tags(id)
);
```

La clé primaire composite sur `(post_id, tag_id)` impose l'unicité au niveau DB.

## Définir les tags atomiquement

L'endpoint `PUT /posts/{id}/tags` remplace tous les tags d'un post en une opération. Supprimer d'abord, puis insérer :

```php
public function setPostTags(int $postId, array $tagNames): array
{
    $this->executor->execute('DELETE FROM post_tags WHERE post_id = ?', [$postId]);

    foreach ($tagNames as $name) {
        $tag = $this->findTagByName($name);
        if ($tag === null) {
            continue; // Ignorer silencieusement les noms de tags inconnus
        }

        $this->executor->execute(
            'INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)',
            [$postId, $tag->id],
        );
    }

    return $this->findTagsByPostId($postId);
}
```

- Supprimer-puis-insérer rend l'opération idempotente : l'appeler deux fois avec le même payload donne le même résultat.
- `INSERT OR IGNORE` prévient une erreur DB si le même nom de tag apparaît deux fois dans le body de la requête.
- Les noms de tags inconnus sont silencieusement ignorés — le client doit créer les tags avant de les assigner.
- Pour effacer tous les tags, envoyer `{"tags": []}`.

## Éviter les requêtes N+1

Lors du chargement d'une liste de posts (ex. pour une recherche basée sur les tags), récupérer tous les tags en une seule requête `IN` plutôt qu'une requête par post :

```php
private function findTagsByPostIds(array $postIds): array
{
    if ($postIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    $rows = $this->executor->fetchAll(
        "SELECT t.*, pt.post_id FROM tags t
         INNER JOIN post_tags pt ON pt.tag_id = t.id
         WHERE pt.post_id IN ({$placeholders})
         ORDER BY t.name ASC",
        $postIds,
    );

    $map = [];
    foreach ($rows as $row) {
        $postId         = (int) $row['post_id'];
        $map[$postId][] = $this->hydrateTag($row);
    }

    return $map;
}
```

Cela retourne `array<int, Tag[]>` indexé par ID de post. Deux requêtes au total quel que soit le nombre de posts.

## Unicité des tags

Les tags ont une contrainte `UNIQUE` sur `name`. La création dupliquée retourne 409 :

```php
public function createTag(string $name, string $now): ?Tag
{
    try {
        $this->executor->execute(
            'INSERT INTO tags (name, created_at) VALUES (?, ?)',
            [$name, $now],
        );
    } catch (\RuntimeException) {
        return null; // null → le handler retourne 409
    }

    return $this->findTagByName($name);
}
```

## Recherche basée sur les tags

Filtrer les posts par tag en utilisant un JOIN :

```sql
SELECT p.* FROM posts p
INNER JOIN post_tags pt ON pt.post_id = p.id
INNER JOIN tags t ON t.id = pt.tag_id
WHERE t.name = ?
ORDER BY p.id DESC
```

Puis charger les tags en lot pour l'ensemble de résultats avec la requête `IN` ci-dessus.

## Résumé des routes

| Méthode | Chemin | Description |
|---|---|---|
| `POST` | `/posts` | Créer un post |
| `GET` | `/posts/{id}` | Obtenir un post avec ses tags |
| `POST` | `/tags` | Créer un tag (dupliqué → 409) |
| `GET` | `/tags` | Lister tous les tags (alphabétiquement) |
| `PUT` | `/posts/{id}/tags` | Remplacer tous les tags d'un post |
| `GET` | `/tags/{name}/posts` | Lister les posts avec ce tag |

## Notes de conception

- Les tags sont des entités gérées par l'application, pas du texte libre. Les clients créent les tags d'abord, puis les assignent.
- Les noms de tags inconnus dans `PUT /posts/{id}/tags` sont silencieusement ignorés. Cela évite un aller-retour pour pré-valider les noms.
- Les noms de tags sont triés alphabétiquement dans les réponses pour une sortie déterministe.
- `GET /tags/{name}/posts` retourne 404 si le tag n'existe pas, distinguant "tag inconnu" de "tag existe mais n'a pas de posts" (qui retourne 200 avec un tableau vide).
