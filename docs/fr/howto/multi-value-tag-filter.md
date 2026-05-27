# How-to : API de filtre par tags multi-valeurs

> **Référence FT** : FT250 (`NENE2-FT/tagfilterlog`) — Filtrage de tags par paramètre de requête multi-valeurs

Démontre le filtrage multi-tags sur une API de posts utilisant une table de jointure M:N normalisée. Supporte la sémantique AND (posts qui ont **tous** les tags donnés) et la sémantique OR (posts qui ont **l'un quelconque** des tags donnés), avec deux formats de requête côté client : séparé par virgule (`?tags=php,api`) et style tableau PHP (`?tags[]=php&tags[]=api`).

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/posts` | Créer un post avec tableau de tags optionnel |
| `GET` | `/posts` | Lister les posts (filtrable par tags, AND ou OR) |
| `GET` | `/posts/{id}` | Obtenir un seul post avec ses tags |

---

## Schéma : table de jointure M:N

```sql
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS post_tags (
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    tag     TEXT    NOT NULL,
    PRIMARY KEY (post_id, tag)
);

CREATE INDEX IF NOT EXISTS idx_post_tags_tag ON post_tags(tag);
```

`PRIMARY KEY(post_id, tag)` est une clé primaire composite — elle applique à la fois l'unicité et sert d'index sur `(post_id, tag)`. Un index séparé sur `tag` seul permet des recherches `WHERE tag IN (...)` efficaces quelle que soit la `post_id`.

**Alternative : approche colonne JSON**

Les tags peuvent être stockés comme un tableau JSON dans une colonne TEXT sur la table `posts` : `tags TEXT NOT NULL DEFAULT '[]'`. C'est plus simple (pas de JOIN), mais ne supporte pas les recherches de tags indexées et nécessite `json_each()` ou `json_extract()` pour le filtrage. La table de jointure M:N est préférée quand la performance de recherche de tags est importante.

---

## Création : déduplication de tags et tri alphabétique

```php
$rawTags = isset($body['tags']) && is_array($body['tags']) ? $body['tags'] : [];
$tags    = array_values(array_filter(array_map(
    static fn (mixed $t): string => is_string($t) ? trim($t) : '',
    $rawTags,
), static fn (string $s): bool => $s !== ''));
```

Les tags sont extraits de la requête, épurés et filtrés aux chaînes non-vides. Les valeurs non-string (nombres, nulls) sont coercées en chaînes vides et supprimées.

Dans une transaction :

```php
$this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($title, $body, $tags, $now): Post {
    $id = $tx->insert(
        'INSERT INTO posts (title, body, created_at) VALUES (?, ?, ?)',
        [$title, $body, $now],
    );

    // Dédupliquer et trier les tags
    $uniqueTags = array_values(array_unique($tags));
    sort($uniqueTags);

    foreach ($uniqueTags as $tag) {
        $tx->insert('INSERT OR IGNORE INTO post_tags (post_id, tag) VALUES (?, ?)', [$id, $tag]);
    }

    return new Post($id, $title, $body, $uniqueTags, $now);
});
```

`array_unique()` + `sort()` déduplique et alphabétise en PHP avant l'écriture. `INSERT OR IGNORE` est une deuxième couche de défense — si la contrainte de clé primaire composite se déclenche (ex: une écriture concurrente), l'insertion est ignorée plutôt que de lancer une exception.

La réponse retourne les tags dans l'ordre trié pour que les appelants voient toujours une liste stable.

---

## Filtre AND : `HAVING COUNT(DISTINCT tag) = N`

```php
public function findByAllTags(array $tags): array
{
    if ($tags === []) {
        return $this->findAll();
    }

    $placeholders = implode(',', array_fill(0, count($tags), '?'));
    $rows         = $this->executor->fetchAll(
        "SELECT p.* FROM posts p
         INNER JOIN post_tags pt ON pt.post_id = p.id
         WHERE pt.tag IN ({$placeholders})
         GROUP BY p.id
         HAVING COUNT(DISTINCT pt.tag) = CAST(? AS INTEGER)
         ORDER BY p.created_at DESC",
        [...$tags, count($tags)],
    );

    return array_map($this->hydrateWithTags(...), $rows);
}
```

`WHERE pt.tag IN (...)` réduit aux lignes qui ont au moins un tag correspondant. `GROUP BY p.id HAVING COUNT(DISTINCT pt.tag) = N` sélectionne uniquement les posts qui correspondent à **tous** les N tags.

**`CAST(? AS INTEGER)` est requis** : PDO lie tous les paramètres comme chaînes par défaut. Dans SQLite, comparer `COUNT(...)` (un entier) à `'2'` (une chaîne) fonctionne à l'exécution pour les cas simples, mais le cast explicite est plus sûr et documente l'intention.

---

## Filtre OR : `SELECT DISTINCT`

```php
public function findByAnyTag(array $tags): array
{
    if ($tags === []) {
        return $this->findAll();
    }

    $placeholders = implode(',', array_fill(0, count($tags), '?'));
    $rows         = $this->executor->fetchAll(
        "SELECT DISTINCT p.* FROM posts p
         INNER JOIN post_tags pt ON pt.post_id = p.id
         WHERE pt.tag IN ({$placeholders})
         ORDER BY p.created_at DESC",
        $tags,
    );

    return array_map($this->hydrateWithTags(...), $rows);
}
```

`SELECT DISTINCT` prévient les lignes dupliquées quand un post correspond à plusieurs tags de la liste IN. Pas de clause `HAVING` nécessaire — un seul tag correspondant qualifie le post.

---

## Format dual des paramètres de requête

L'endpoint de liste accepte les tags dans deux formats pour accommoder différents clients :

| Format | Exemple | Source |
|--------|---------|--------|
| Séparé par virgule | `?tags=php,api` | `QueryStringParser::commaSeparated()` |
| Style tableau PHP | `?tags[]=php&tags[]=api` | PSR-7 `getQueryParams()` |

```php
private function extractTags(ServerRequestInterface $request): array
{
    // Stratégie 1 : séparé par virgule (natif NENE2)
    $csv = QueryStringParser::commaSeparated($request, 'tags');
    if ($csv !== null) {
        return $csv;
    }

    // Stratégie 2 : paramètres de tableau style PHP (?tags[]=php&tags[]=api)
    // getQueryParams() PSR-7 analyse la syntaxe de tableau PHP nativement.
    // QueryStringParser de NENE2 n'a pas d'aide pour cela — utiliser l'accès brut.
    $params   = $request->getQueryParams();
    $arrayVal = $params['tags'] ?? null;

    if (is_array($arrayVal)) {
        /** @var list<string> $filtered */
        $filtered = array_values(array_filter(
            array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : '', $arrayVal),
            static fn (string $s): bool => $s !== '',
        ));

        return $filtered;
    }

    return [];
}
```

`QueryStringParser::commaSeparated()` gère `?tags=php,api` et retourne `null` si le paramètre est absent. Quand `null`, le fallback vérifie `getQueryParams()['tags']`, que les implémentations PSR-7 analysent depuis `?tags[]=php&tags[]=api` comme un tableau PHP.

Le paramètre mode sélectionne AND vs OR :

```php
$mode  = QueryStringParser::string($request, 'mode') ?? 'all'; // 'all' = AND, 'any' = OR
$posts = $mode === 'any'
    ? $this->repository->findByAnyTag($tags)
    : $this->repository->findByAllTags($tags);
```

Les valeurs `mode` inconnues passent au AND (le défaut plus sûr — moins de résultats).

---

## Hydratation : N+1 requêtes par post

```php
private function hydrateWithTags(array $row): Post
{
    $tagRows = $this->executor->fetchAll(
        'SELECT tag FROM post_tags WHERE post_id = ? ORDER BY tag ASC',
        [(int) $row['id']],
    );

    $tags = array_map(static fn (array $r): string => (string) $r['tag'], $tagRows);

    return new Post(
        id:        (int) $row['id'],
        title:     (string) $row['title'],
        body:      (string) $row['body'],
        tags:      $tags,
        createdAt: (string) $row['created_at'],
    );
}
```

Cela effectue une requête supplémentaire par post pour charger ses tags. Pour les petits datasets c'est acceptable. Pour les grands jeux de résultats, remplacer par une seule requête `GROUP_CONCAT` ou `json_group_array` :

```sql
SELECT p.*, GROUP_CONCAT(pt.tag ORDER BY pt.tag) AS tags_csv
FROM posts p
LEFT JOIN post_tags pt ON pt.post_id = p.id
GROUP BY p.id
ORDER BY p.created_at DESC
```

Puis découper `tags_csv` avec `explode(',', ...)` en PHP. Notez que `GROUP_CONCAT` de SQLite ne garantit pas l'ordre sans `ORDER BY` à l'intérieur de l'agrégat (SQLite 3.39+ supporte `ORDER BY` dans `GROUP_CONCAT`).

---

## Comparaison AND vs OR

| Mode | Pattern SQL | Posts avec `[php, api]` vs `[php]` vs `[js]` |
|------|-------------|-----------------------------------------------|
| AND (`mode=all`) | `HAVING COUNT(DISTINCT tag) = N` | Seul `[php, api]` correspond à `?tags=php,api` |
| OR (`mode=any`) | `SELECT DISTINCT` | Les deux `[php, api]` et `[php]` correspondent à `?tags=php,api` |
| Pas de tags | Pas de filtre | Tous les posts retournés |

La liste de tags vide (`tags=[]` ou `tags` absent) retourne toujours tous les posts dans les deux modes.

---

## Howtos associés

- [`tagging-system.md`](tagging-system.md) — gestion de tags/labels avec relations M:N scopées par entité
- [`tag-label-api.md`](tag-label-api.md) — taxonomie de tags avec CRUD d'entité tag et filtrage de liste
- [`note-management-with-tags.md`](note-management-with-tags.md) — tags de notes avec scoping par propriétaire
- [`cursor-pagination.md`](cursor-pagination.md) — combinaison pagination curseur avec filtre de tags
