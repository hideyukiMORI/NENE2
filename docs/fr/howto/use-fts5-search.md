# Utiliser la recherche plein texte FTS5 de SQLite

L'extension FTS5 de SQLite fournit une recherche plein texte via un index inversé. Ce guide couvre le pattern de schéma, la synchronisation par déclencheurs, et les pièges de syntaxe de requête que vous rencontrerez lors de l'acceptation d'entrées utilisateur.

---

## 1. Schéma : table virtuelle + contenu externe + déclencheurs

Utiliser `content=<table>` pour conserver vos données dans une table normale et laisser FTS5 maintenir uniquement l'index de recherche :

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    author     TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE VIRTUAL TABLE articles_fts USING fts5(
    title,
    body,
    author,
    content=articles,   -- FTS5 lit le contenu depuis la table articles
    content_rowid=id    -- mappe le rowid FTS5 à articles.id
);

-- Maintenir l'index FTS en synchronisation avec la table de base
CREATE TRIGGER articles_ai AFTER INSERT ON articles BEGIN
    INSERT INTO articles_fts(rowid, title, body, author)
    VALUES (new.id, new.title, new.body, new.author);
END;

CREATE TRIGGER articles_au AFTER UPDATE ON articles BEGIN
    -- Supprimer l'ancienne ligne de l'index, puis insérer la ligne mise à jour
    INSERT INTO articles_fts(articles_fts, rowid, title, body, author)
    VALUES ('delete', old.id, old.title, old.body, old.author);
    INSERT INTO articles_fts(rowid, title, body, author)
    VALUES (new.id, new.title, new.body, new.author);
END;

CREATE TRIGGER articles_ad AFTER DELETE ON articles BEGIN
    INSERT INTO articles_fts(articles_fts, rowid, title, body, author)
    VALUES ('delete', old.id, old.title, old.body, old.author);
END;
```

> **Pourquoi des déclencheurs ?** FTS5 avec `content=` ne suit pas automatiquement les modifications — vous devez maintenir l'index avec des déclencheurs. Le pattern `INSERT INTO fts(fts, rowid, ...) VALUES ('delete', ...)` est la façon dont FTS5 supprime une ligne de l'index.

---

## 2. Requête de recherche

```php
$rows = $this->executor->fetchAll(
    "SELECT a.*, snippet(articles_fts, 1, '<b>', '</b>', '…', 10) AS snippet, rank
     FROM articles_fts
     JOIN articles a ON a.id = articles_fts.rowid
     WHERE articles_fts MATCH ?
     ORDER BY rank
     LIMIT ? OFFSET ?",
    [$query, $limit, $offset],
);
```

- `rank` est une colonne virtuelle exposée par FTS5. Les valeurs plus basses (plus négatives) sont plus pertinentes. `ORDER BY rank` place les meilleures correspondances en premier.
- `snippet(table, column_index, open, close, ellipsis, token_count)` retourne un fragment mis en évidence. L'index de colonne est basé sur 0 : `0` = title, `1` = body.

---

## 3. Pièges de syntaxe de requête

### 3.1 Le tiret est un préfixe de filtre de colonne — pas un séparateur de phrase

**C'est la source de bugs la plus courante lors de l'acceptation d'entrées utilisateur.**

FTS5 interprète `mot-autre` comme un filtre de colonne : il cherche le terme `autre` dans la colonne nommée `mot`. Si `mot` n'est pas une colonne dans la table FTS5, SQLite lève une erreur :

```
General error: 1 no such column: text
```

```
full-text   ← ERREUR : "chercher dans la colonne 'full' pour 'text'" — mais 'full' n'est pas une colonne
full text   ← OK : requête AND (correspond aux docs avec les deux "full" ET "text")
"full text" ← OK : requête de phrase (ordre consécutif exact)
```

Cette erreur se propage comme une `DatabaseConnectionException` et entraîne une réponse 500 si vous ne nettoyez pas l'entrée d'abord.

**Nettoyer l'entrée utilisateur avant de la passer à FTS5 :**

```php
private function sanitizeFtsQuery(string $query): string
{
    // Remplacer les tirets par des espaces : "full-text" devient "full text" (logique AND)
    return str_replace('-', ' ', $query);
}
```

Ou échapper avec des guillemets doubles pour une correspondance de phrase :

```php
private function sanitizeFtsQuery(string $query): string
{
    // Envelopper toute la requête dans des guillemets doubles pour forcer la correspondance de phrase
    $escaped = str_replace('"', '""', $query);
    return '"' . $escaped . '"';
}
```

### 3.2 Pas de racinisation par défaut

Le tokeniseur `unicode61` par défaut ne fait pas de racinisation. `framework` ne correspond pas à `frameworks`, et `run` ne correspond pas à `running`.

Options :

| Approche | Comment |
|---|---|
| Correspondance exacte | Utiliser les formes de mots exactes à la fois dans les documents et les requêtes |
| Correspondance de préfixe | Ajouter `*` au terme de requête : `framework*` correspond à `framework`, `frameworks`, `framework-agnostic` |
| Raciniseur Porter | Déclarer `tokenize='porter ascii'` dans l'instruction `CREATE VIRTUAL TABLE` |

**Exemple de correspondance de préfixe :**

```php
// L'utilisateur tape "frame" → ajouter * pour correspondre à "framework", "frameworks", etc.
$query = trim($userInput) . '*';
```

**Raciniseur Porter :**

```sql
CREATE VIRTUAL TABLE articles_fts USING fts5(
    title, body, author,
    content=articles,
    content_rowid=id,
    tokenize='porter ascii'  -- racinisation anglaise
);
```

> Le tokeniseur `porter` n'est disponible que lorsque SQLite est compilé avec le support FTS5 (les builds standard l'incluent). Il est utile pour le texte anglais ; pour d'autres langues, envisager une racinisation externe avant l'indexation.

### 3.3 Opérateurs AND / OR / NOT

Syntaxe de requête FTS5 :

| Syntaxe | Signification |
|---------|---------------|
| `un deux` | AND : les deux doivent être présents |
| `un OR deux` | OR : l'un ou l'autre doit être présent |
| `un NOT deux` | NOT : premier présent, second absent |
| `"un deux"` | Phrase : ordre consécutif exact |
| `un*` | Préfixe : correspond à `un`, `uns`, `uni` (préfixe sur `un`) |
| `title:requête` | Filtre de colonne : restreindre la correspondance à la colonne `title` |

> **Note** : `NOT` doit être en majuscules. Le `not` minuscule est traité comme un terme de recherche.

---

## 4. Compter les résultats de recherche

Compter avec une requête séparée — `COUNT(*)` sur la correspondance FTS5 :

```php
$count = $this->executor->fetchOne(
    'SELECT COUNT(*) AS cnt FROM articles_fts WHERE articles_fts MATCH ?',
    [$query],
);
```

---

## 5. Exemple de repository complet

```php
final readonly class ArticleRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {}

    /** @return list<array<string, mixed>> */
    public function search(string $userQuery, int $limit, int $offset): array
    {
        $query = $this->sanitizeFtsQuery($userQuery);

        return $this->executor->fetchAll(
            "SELECT a.*, snippet(articles_fts, 1, '<b>', '</b>', '…', 10) AS snippet, rank
             FROM articles_fts
             JOIN articles a ON a.id = articles_fts.rowid
             WHERE articles_fts MATCH ?
             ORDER BY rank
             LIMIT ? OFFSET ?",
            [$query, $limit, $offset],
        );
    }

    public function countSearch(string $userQuery): int
    {
        $query = $this->sanitizeFtsQuery($userQuery);
        $row   = $this->executor->fetchOne(
            'SELECT COUNT(*) AS cnt FROM articles_fts WHERE articles_fts MATCH ?',
            [$query],
        );

        return (int) ($row['cnt'] ?? 0);
    }

    private function sanitizeFtsQuery(string $query): string
    {
        // Remplacer les tirets par des espaces : "full-text" → "full text" (logique AND)
        return str_replace('-', ' ', trim($query));
    }
}
```
