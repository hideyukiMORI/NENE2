# Pagination

Deux patterns sont disponibles pour paginer les endpoints de liste : **OFFSET** et **curseur** (keyset). Choisissez en fonction du volume de données et des exigences UI.

## Comparaison rapide

| | OFFSET | Curseur |
|---|---|---|
| Implémentation | Simple | Modérée (pattern fetch+1) |
| Nombre total | Nécessite `COUNT(*)` | Pas nécessaire |
| Vitesse sur pages profondes | Se dégrade linéairement | Constante (seek d'index) |
| UI avec numéro de page | Facile | Difficile |
| Défilement infini / flux | Fragile (dérive de lignes) | Stable |
| Changements de données pendant la navigation | Peut causer une dérive de lignes | Stable |

**Règle empirique :** Utiliser OFFSET pour les tables admin avec numéros de pages et petits datasets. Utiliser le curseur pour les flux, le défilement infini et toute table avec plus de ~10 000 lignes.

## Pagination OFFSET

```php
private function listByOffset(ServerRequestInterface $request): ResponseInterface
{
    $limit  = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
    $offset = max(0, QueryStringParser::int($request, 'offset', 0) ?? 0);

    $items = $repo->fetchAll(
        'SELECT * FROM articles ORDER BY id DESC LIMIT ? OFFSET ?',
        [$limit, $offset],
    );
    $total = $repo->fetchOne('SELECT COUNT(*) AS cnt FROM articles', [])['cnt'] ?? 0;

    return $json->create([
        'items'       => $items,
        'limit'       => $limit,
        'offset'      => $offset,
        'total'       => (int) $total,
        'has_more'    => ($offset + $limit) < (int) $total,
        'next_offset' => ($offset + $limit) < (int) $total ? $offset + $limit : null,
    ]);
}
```

**Pourquoi OFFSET devient plus lent** : La base de données doit scanner et ignorer toutes les lignes avant l'offset. Pour `OFFSET 5000`, le moteur lit 5001 lignes et jette les 5000 premières. Vous pouvez vérifier avec SQLite :

```sql
EXPLAIN QUERY PLAN
SELECT * FROM articles ORDER BY id DESC LIMIT 20 OFFSET 5000;
-- SCAN articles USING INDEX idx_articles_id_desc
-- Le scan touche toujours 5020 lignes.
```

## Pagination par curseur

Le curseur est l'`id` de la dernière ligne vue. Chaque page récupère les lignes "avant" le curseur (pour l'ordre descendant) avec `WHERE id < cursor`, que l'index sert avec un seek — aucune ligne avant le curseur n'est touchée.

```php
private function listByCursor(ServerRequestInterface $request): ResponseInterface
{
    $limit   = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
    $afterId = QueryStringParser::int($request, 'after'); // null = première page

    // pattern fetch+1 : détecter has_more sans requête COUNT
    $fetch = $limit + 1;

    if ($afterId === null) {
        $rows = $repo->fetchAll(
            'SELECT * FROM articles ORDER BY id DESC LIMIT ?',
            [$fetch],
        );
    } else {
        $rows = $repo->fetchAll(
            'SELECT * FROM articles WHERE id < ? ORDER BY id DESC LIMIT ?',
            [$afterId, $fetch],
        );
    }

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows); // supprimer la ligne sentinelle supplémentaire
    }

    $nextCursor = $hasMore && $rows !== [] ? (int) end($rows)['id'] : null;

    return $json->create([
        'items'       => $rows,
        'limit'       => $limit,
        'has_more'    => $hasMore,
        'next_cursor' => $nextCursor,
    ]);
}
```

### Le pattern fetch+1

Pour savoir s'il y a une page suivante sans émettre un `COUNT(*)` :

1. Demander `limit + 1` lignes.
2. Si le résultat a plus de `limit` lignes, il y a une page suivante.
3. Supprimer la dernière ligne (`array_pop`) avant de retourner.
4. Utiliser l'`id` de la dernière ligne restante comme `next_cursor`.

Cela évite une requête supplémentaire au prix de toujours récupérer une ligne de plus.

### Utilisation côté client

```
GET /articles/cursor?limit=20
→ { items: [...20 articles], has_more: true, next_cursor: 42 }

GET /articles/cursor?limit=20&after=42
→ { items: [...20 articles], has_more: true, next_cursor: 22 }

GET /articles/cursor?limit=20&after=22
→ { items: [...2 articles], has_more: false, next_cursor: null }
```

## Limitation de la plage de limite

Toujours limiter la plage de limite à une valeur raisonnable pour éviter les requêtes non bornées :

```php
$limit = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
```

Cela accepte `1–100` et utilise `20` par défaut quand le paramètre est absent.

## Quand passer de OFFSET au curseur

Un guide approximatif basé sur la taille de la table et la profondeur de page typique :

| Lignes | Profondeur typique | Recommandation |
|--------|-------------------|----------------|
| < 10 000 | N'importe laquelle | L'un ou l'autre fonctionne ; OFFSET est plus simple |
| 10 000–100 000 | Superficielle (page 1–5) | L'un ou l'autre ; ajouter un index sur la colonne de tri |
| 10 000–100 000 | Profonde (page 10+) | Curseur préféré |
| > 100 000 | N'importe laquelle | Curseur fortement recommandé |

Ajoutez un index sur votre colonne de tri quelle que soit l'approche choisie :

```sql
CREATE INDEX idx_articles_id_desc ON articles (id DESC);
```

## Comparaison des résultats à la même position

Lors de la migration de OFFSET vers curseur, vérifiez la correction en récupérant la même "fenêtre" de lignes des deux façons :

```php
// OFFSET : lignes 11–20 (offset=10 à base 0)
$offsetPage = $get('/articles/offset?limit=10&offset=10');

// Curseur : récupérer l'id en position 10 (offset=9), l'utiliser comme ancre
$anchor     = $get('/articles/offset?limit=1&offset=9');
$anchorId   = $anchor['items'][0]['id'];
$cursorPage = $get("/articles/cursor?limit=10&after={$anchorId}");

// Ces résultats doivent être identiques
assert(array_column($offsetPage['items'], 'id') === array_column($cursorPage['items'], 'id'));
```
