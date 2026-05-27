# How-to : Recherche plein texte SQLite FTS5

> **Référence FT** : FT254 (`NENE2-FT/ftslog`) — Recherche plein texte avec SQLite FTS5

Démontre la recherche plein texte (FTS) en utilisant l'extension FTS5 intégrée de SQLite. Une table virtuelle
`posts_fts` reflète la table `posts` et est maintenue synchronisée via des triggers.
La recherche utilise `MATCH` avec des résultats classés par pertinence via `fts.rank`.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/posts` | Créer un post (indexé automatiquement) |
| `GET` | `/posts` | Lister tous les posts |
| `GET` | `/posts/search` | Recherche plein texte (`?q=`) |

> **Ordre des routes** : `/posts/search` doit être enregistré **avant** `/posts/{id}` pour que
> le segment littéral `search` ne soit pas capturé comme paramètre de chemin.

---

## Schéma : table virtuelle FTS5 + triggers

```sql
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '',  -- chaîne de tags séparés par des espaces
    created_at TEXT    NOT NULL
);

-- Table virtuelle FTS5 : reflète posts pour la recherche plein texte
CREATE VIRTUAL TABLE IF NOT EXISTS posts_fts USING fts5(
    title,
    body,
    tags,
    content='posts',      -- table de contenu externe
    content_rowid='id'    -- colonne rowid dans la table de contenu
);

-- Maintenir l'index FTS synchronisé avec posts
CREATE TRIGGER IF NOT EXISTS posts_ai AFTER INSERT ON posts BEGIN
    INSERT INTO posts_fts(rowid, title, body, tags)
    VALUES (new.id, new.title, new.body, new.tags);
END;

CREATE TRIGGER IF NOT EXISTS posts_ad AFTER DELETE ON posts BEGIN
    INSERT INTO posts_fts(posts_fts, rowid, title, body, tags)
    VALUES ('delete', old.id, old.title, old.body, old.tags);
END;

CREATE TRIGGER IF NOT EXISTS posts_au AFTER UPDATE ON posts BEGIN
    INSERT INTO posts_fts(posts_fts, rowid, title, body, tags)
    VALUES ('delete', old.id, old.title, old.body, old.tags);
    INSERT INTO posts_fts(rowid, title, body, tags)
    VALUES (new.id, new.title, new.body, new.tags);
END;
```

**`content='posts'`** déclare `posts_fts` comme table de contenu — elle stocke les tokens FTS
mais délègue le stockage réel du texte à `posts`. Cela évite de dupliquer le texte complet.

**`content_rowid='id'`** indique à FTS5 quelle colonne dans `posts` est le rowid à utiliser pour les jointures.

**Les triggers** maintiennent l'index FTS synchronisé. Sans eux, les insertions et mises à jour dans `posts`
ne seraient pas reflétées dans `posts_fts`. Le trigger de suppression utilise la syntaxe de commande spéciale
`'delete'` pour supprimer une ligne de l'index FTS.

---

## Tags comme chaîne séparée par des espaces

```php
$tags = isset($body['tags']) && is_string($body['tags']) ? trim($body['tags']) : '';
// ex. "php api backend"
$post = $this->repo->create($title, $postBody, $tags, $now);
```

Les tags sont stockés comme chaîne séparée par des espaces (ex. `"php api backend"`) plutôt que comme
tableau JSON ou table de jointure M:N. Cela rend les tags recherchables par FTS5 sans aucun JOIN —
une recherche de `kubernetes` correspond à un post tagué `"docker kubernetes devops"`.

**Compromis** :

| Approche | FTS recherchable | Filtre de tag exact | Entité de tag canonique |
|---|---|---|---|
| Chaîne séparée par espaces | ✅ | ❌ (LIKE nécessaire) | ❌ |
| Table de jointure M:N | ❌ (JOIN requis) | ✅ (clause IN) | ✅ |
| Colonne JSON array | Limité (`json_each`) | Limité | ❌ |

Utiliser l'approche de table de jointure M:N (voir [`multi-value-tag-filter.md`](multi-value-tag-filter.md))
quand le filtrage exact par tag est le cas d'utilisation principal.

---

## Requête de recherche plein texte : `MATCH` + `rank`

```php
public function search(string $query): array
{
    if (trim($query) === '') {
        return [];
    }

    $rows = $this->executor->fetchAll(
        'SELECT p.*, fts.rank
         FROM posts_fts fts
         JOIN posts p ON p.id = fts.rowid
         WHERE posts_fts MATCH ?
         ORDER BY fts.rank',
        [$query],
    );

    return array_map(
        static fn (array $row): SearchResult => new SearchResult(
            new Post(...),
            (float) $row['rank'],
        ),
        $rows,
    );
}
```

`WHERE posts_fts MATCH ?` recherche dans toutes les colonnes indexées (`title`, `body`, `tags`).
Le placeholder `?` est une valeur paramétrée — la chaîne de requête n'est pas interpolée
dans le SQL, donc elle ne peut pas altérer la structure de la requête.

`fts.rank` est un flottant négatif — les valeurs inférieures (plus négatives) indiquent une pertinence plus élevée.
`ORDER BY fts.rank` trie les meilleures correspondances en premier (ascendant, ce qui est le plus pertinent en premier).

---

## Syntaxe de requête FTS5

FTS5 supporte un langage de requête riche passé comme valeur MATCH :

| Requête | Correspond à |
|---------|-------------|
| `php` | Tout post contenant "php" |
| `php api` | Posts contenant "php" OU "api" (défaut : OR implicite) |
| `php AND api` | Posts contenant "php" et "api" |
| `"quick brown"` | Posts contenant la phrase exacte "quick brown" |
| `php*` | Posts où n'importe quel token commence par "php" (recherche de préfixe) |
| `title:php` | Posts où la colonne title contient "php" |
| `php NOT python` | Posts avec "php" mais pas "python" |

La recherche de phrase (`"..."`) correspond aux séquences exactes de tokens.
La recherche scopée par colonne (`title:php`) limite la correspondance à une colonne.

---

## Gestion des requêtes invalides : try-catch → 400

FTS5 lève une `PDOException` (ou l'enveloppe) quand la syntaxe de la requête est invalide :

```php
private function searchPosts(ServerRequestInterface $request): ResponseInterface
{
    $query = isset($params['q']) && is_string($params['q']) ? trim($params['q']) : '';

    if ($query === '') {
        return $this->json->create(['error' => 'q query parameter is required'], 422);
    }

    try {
        $results = $this->repo->search($query);
    } catch (\Exception $e) {
        // FTS5 lève sur les erreurs de syntaxe (ex. guillemets non fermés : '"unclosed')
        return $this->json->create(['error' => 'invalid search query'], 400);
    }

    return $this->json->create([...]);
}
```

Les requêtes FTS invalides (guillemets non fermés, opérateurs mal formés) résultent en une exception DB.
La capturer et retourner `400 Bad Request` empêche un `500` de fuiter vers le client.

---

## Insensibilité à la casse

FTS5 est insensible à la casse par défaut pour les caractères ASCII. Une recherche de `php` correspond à
des posts contenant `PHP`, `Php`, ou `php`. Le folding de casse non-ASCII nécessite un tokenizer personnalisé
(`unicode61` ou `ascii`). Le tokenizer `porter` par défaut applique le stemming pour les mots anglais.

---

## Réponse de recherche

```json
GET /posts/search?q=php

{
    "query": "php",
    "total": 2,
    "items": [
        {
            "id": 1,
            "title": "PHP Framework",
            "body": "Building APIs with PHP",
            "tags": "php backend",
            "created_at": "2026-05-27T10:00:00Z",
            "rank": -1.234
        }
    ]
}
```

`rank` est inclus dans chaque résultat à des fins d'affichage ou de tri côté client.
`rank` inférieur (plus négatif) = pertinence plus élevée.

---

## Comparaison : FTS5 vs recherche LIKE

| Fonctionnalité | FTS5 MATCH | LIKE `%terme%` |
|---|---|---|
| Indexé | ✅ | ❌ (scan complet) |
| Classement par pertinence | ✅ (`rank`) | ❌ |
| Recherche multi-mots | ✅ (naturel) | ❌ (plusieurs LIKE requis) |
| Recherche de phrase | ✅ (`"..."`) | Partiel (`%quick brown%`) |
| Insensible à la casse | ✅ (ASCII) | ✅ (avec NOCASE) |
| Recherche de préfixe | ✅ (`php*`) | ✅ (`php%`) |
| Scopé par colonne | ✅ (`title:php`) | ❌ |
| Coût de configuration | Table virtuelle FTS + triggers | Aucun |

FTS5 est préféré pour les grands jeux de données où la recherche est une fonctionnalité principale. LIKE est
suffisant pour les petites tables ou la simple autocomplétion par préfixe.

---

## Howtos connexes

- [`use-fts5-search.md`](use-fts5-search.md) — Ajouter FTS5 à une table existante
- [`search-autocomplete.md`](search-autocomplete.md) — Autocomplétion par préfixe basée sur LIKE (searchlog FT157)
- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — Filtrage de tags M:N avec sémantique AND/OR
- [`event-analytics-api.md`](event-analytics-api.md) — `json_extract()` pour la recherche de propriétés JSON
