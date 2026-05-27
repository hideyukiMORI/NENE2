# How-to : API de marque-pages URL avec filtrage par tag

> **Référence FT** : FT265 (`NENE2-FT/linklog`) — API de marque-pages URL : contrainte UNIQUE sur URL, stockage de tags séparés par virgules, correspondance de tags par LIKE

Démontre une API de marque-pages qui stocke les URLs avec des tags dans une colonne TEXT séparée par des virgules. Les URLs dupliquées sont détectées via une contrainte `UNIQUE` et remontent comme `DuplicateUrlException` mappée à 409 Conflict. Le filtrage par tags utilise quatre patterns LIKE pour faire correspondre un tag quelle que soit sa position dans la chaîne séparée par des virgules.

---

## Routes

| Méthode   | Chemin        | Description                                         |
|-----------|---------------|-----------------------------------------------------|
| `POST`    | `/links`      | Créer un marque-page                               |
| `GET`     | `/links`      | Lister les marque-pages (recherche + filtre tag, paginé) |
| `GET`     | `/links/{id}` | Obtenir un seul marque-page                        |
| `DELETE`  | `/links/{id}` | Supprimer un marque-page                           |

---

## Schéma

```sql
CREATE TABLE IF NOT EXISTS links (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL UNIQUE,
    title       TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    tags        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL
);
```

`url TEXT NOT NULL UNIQUE` impose un marque-page par URL au niveau DB.
`tags TEXT` stocke une liste séparée par des virgules (ex. `"php,api,rest"`). Cela évite une
table de jointure `link_tags` séparée pour les cas d'usage à petite échelle.

---

## Tags : TEXT séparé par virgules vs table de jointure M:N

| Approche | Complexité de requête | Quand utiliser |
|---|---|---|
| TEXT séparé par virgules | Patterns LIKE (4 par tag) | Petits datasets ; requêtes de tags rares |
| Table de jointure M:N (`link_tags`) | JOIN + GROUP BY ou IN | Grands datasets ; filtrage AND/OR fréquent |
| FTS5 avec colonne tags | `WHERE fts MATCH ?` | Recherche plein texte sur plusieurs colonnes |

Le TEXT séparé par virgules est plus simple à implémenter et convient quand le nombre de liens
et de tags est modeste. Pour les datasets avec des milliers de liens et des requêtes de tags
complexes (filtre AND, comptages exacts), une table de jointure (voir
[`multi-value-tag-filter.md`](multi-value-tag-filter.md)) est préférable.

---

## Correspondance LIKE de tags : quatre patterns

Un tag stocké dans une colonne séparée par des virgules peut apparaître dans quatre positions :
1. **Correspondance exacte** : `tags = 'php'` (seul tag)
2. **Au début** : `tags LIKE 'php,%'` (premier de plusieurs)
3. **Au milieu** : `tags LIKE '%,php,%'` (pas premier, pas dernier)
4. **À la fin** : `tags LIKE '%,php'` (dernier de plusieurs)

```php
if ($tags !== null) {
    foreach ($tags as $tag) {
        $sql      .= ' AND (tags = ? OR tags LIKE ? OR tags LIKE ? OR tags LIKE ?)';
        $params[]  = $tag;            // exact : "php"
        $params[]  = $tag . ',%';     // préfixe : "php,..."
        $params[]  = '%,' . $tag . ',%';  // milieu : "...,php,..."
        $params[]  = '%,' . $tag;     // suffixe : "...,php"
    }
}
```

Les quatre patterns sont liés par AND par tag : un lien doit correspondre à tous les tags demandés. Cela implémente un filtre AND sur les tags. Chaque `?` est un binding paramétré — pas de risque d'injection.

**Limitation** : une requête pour le tag `ph` ne correspondrait PAS à un tag stocké `php` parce que les patterns vérifient les délimiteurs exacts (`,` ou limites de chaîne). Les tags sont mis en correspondance par valeur de chaîne exacte, pas par sous-chaîne.

---

## Sérialisation et désérialisation des tags séparés par virgules

**Stockage** : `implode(',', $tags)` — `['php', 'api', 'rest']` → `'php,api,rest'`

**Lecture** :
```php
$tagsStr = (string) $row['tags'];
$tags    = $tagsStr === '' ? [] : array_values(array_filter(explode(',', $tagsStr)));
```

`array_filter()` supprime les chaînes vides créées par des virgules de début/fin ou des virgules doubles.
`array_values()` réindexe en `list<string>`.

**Parsing de requête de tag** : `?tags=php,api` → découper sur la virgule → `['php', 'api']`

```php
$rawTags = QueryStringParser::string($request, 'tags');
$tags    = $rawTags !== null
    ? array_values(array_filter(array_map('trim', explode(',', $rawTags))))
    : null;
```

---

## URL dupliquée : exception personnalisée + handler

```php
public function create(string $url, ...): Link
{
    try {
        $this->executor->execute(
            'INSERT INTO links (url, title, description, tags, created_at) VALUES (?, ?, ?, ?, ?)',
            [$url, $title, $description, implode(',', $tags), $createdAt],
        );
    } catch (DatabaseConnectionException $e) {
        $previous = $e->getPrevious();
        if ($previous !== null && str_contains($previous->getMessage(), 'UNIQUE constraint failed')) {
            throw new DuplicateUrlException($url);
        }
        throw $e;
    }

    return new Link($this->executor->lastInsertId(), ...);
}
```

Le repository capture la `DatabaseConnectionException` générique (levée par le framework quand une exception PDO survient), inspecte le message de l'exception précédente pour `UNIQUE constraint failed`, et relève comme `DuplicateUrlException` spécifique au domaine. Cela garde le langage du domaine (`DuplicateUrlException`) séparé du détail d'infrastructure (`PDOException`).

Le middleware `DuplicateUrlExceptionHandler` capture `DuplicateUrlException` et retourne un Problem Details 409 Conflict :

```php
return $this->problems->create($request, 'duplicate-url', 'URL already exists.', 409, $e->url);
```

---

## Recherche : LIKE sur le titre et l'URL

```php
if ($search !== null) {
    $sql      .= ' AND (title LIKE ? OR url LIKE ?)';
    $params[]  = '%' . $search . '%';
    $params[]  = '%' . $search . '%';
}
```

La requête de recherche est appliquée aux colonnes `title` et `url`. Un seul binding `$search` est répété pour les deux colonnes. Comme pour le filtrage par tags, le joker `%` est un littéral SQL dans la chaîne de requête, pas depuis l'entrée utilisateur — le terme de recherche de l'utilisateur est lié comme paramètre.

---

## Exemple : filtre AND de tags

**Requête** : `GET /links?tags=php,api`

Correspond aux liens qui ont BOTH `php` AND `api` dans leur colonne `tags` :
- `"php,api"` ✓ (php : correspondance préfixe, api : correspondance suffixe)
- `"rest,php,api"` ✓ (php : correspondance milieu, api : correspondance suffixe)
- `"php"` ✗ (manque `api`)

---

## Howtos liés

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — table de jointure M:N avec filtrage AND/OR de tags (pour les plus grands datasets)
- [`sqlite-fts5-search.md`](sqlite-fts5-search.md) — recherche plein texte FTS5 comme alternative à LIKE
- [`sql-injection-defence.md`](sql-injection-defence.md) — patterns LIKE paramétrés et défense contre l'injection
