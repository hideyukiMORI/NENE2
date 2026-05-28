# Tagging-System (M:N)

Tags an Beiträge über eine Many-to-Many-Join-Tabelle anhängen, mit atomarem Tag-Ersetzen und N+1-freiem Tag-Laden.

## Übersicht

Ein Tagging-System hat drei Tabellen: `posts`, `tags` und `post_tags` (die Join-Tabelle). Beiträge und Tags haben eine M:N-Beziehung — ein Beitrag hat viele Tags, ein Tag gehört zu vielen Beiträgen.

## Datenbankschema

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

Der zusammengesetzte Primärschlüssel auf `(post_id, tag_id)` erzwingt Eindeutigkeit auf DB-Ebene.

## Tags atomar setzen

Der `PUT /posts/{id}/tags`-Endpunkt ersetzt alle Tags für einen Beitrag in einer Operation. Erst löschen, dann einfügen:

```php
public function setPostTags(int $postId, array $tagNames): array
{
    $this->executor->execute('DELETE FROM post_tags WHERE post_id = ?', [$postId]);

    foreach ($tagNames as $name) {
        $tag = $this->findTagByName($name);
        if ($tag === null) {
            continue; // Unbekannte Tag-Namen stillschweigend überspringen
        }

        $this->executor->execute(
            'INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)',
            [$postId, $tag->id],
        );
    }

    return $this->findTagsByPostId($postId);
}
```

- Löschen-dann-Einfügen macht die Operation idempotent: sie zweimal mit demselben Payload aufzurufen hat dasselbe Ergebnis.
- `INSERT OR IGNORE` verhindert einen DB-Fehler, wenn derselbe Tag-Name zweimal im Request-Body vorkommt.
- Unbekannte Tag-Namen werden stillschweigend übersprungen — der Client muss Tags erstellen, bevor er sie zuweist.
- Um alle Tags zu löschen, `{"tags": []}` senden.

## N+1-Abfragen vermeiden

Beim Laden einer Liste von Beiträgen (z. B. für tag-basierte Suche) alle Tags in einer einzelnen `IN`-Abfrage laden statt eine Abfrage pro Beitrag:

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

Dies gibt `array<int, Tag[]>` zurück, indexiert nach Beitrags-ID. Insgesamt zwei Abfragen unabhängig von der Anzahl der Beiträge.

## Tag-Eindeutigkeit

Tags haben eine `UNIQUE`-Bedingung auf `name`. Doppelte Erstellung gibt 409 zurück:

```php
public function createTag(string $name, string $now): ?Tag
{
    try {
        $this->executor->execute(
            'INSERT INTO tags (name, created_at) VALUES (?, ?)',
            [$name, $now],
        );
    } catch (\RuntimeException) {
        return null; // null → Handler gibt 409 zurück
    }

    return $this->findTagByName($name);
}
```

## Tag-basierte Suche

Beiträge nach Tag über einen JOIN filtern:

```sql
SELECT p.* FROM posts p
INNER JOIN post_tags pt ON pt.post_id = p.id
INNER JOIN tags t ON t.id = pt.tag_id
WHERE t.name = ?
ORDER BY p.id DESC
```

Dann Tags für das Ergebnis-Set mit der `IN`-Abfrage oben stapelweise laden.

## Routen-Übersicht

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/posts` | Beitrag erstellen |
| `GET` | `/posts/{id}` | Beitrag mit Tags abrufen |
| `POST` | `/tags` | Tag erstellen (Duplikat → 409) |
| `GET` | `/tags` | Alle Tags auflisten (alphabetisch) |
| `PUT` | `/posts/{id}/tags` | Alle Tags eines Beitrags ersetzen |
| `GET` | `/tags/{name}/posts` | Beiträge mit einem bestimmten Tag auflisten |

## Design-Hinweise

- Tags sind anwendungsverwaltete Entitäten, kein freier Text. Clients erstellen Tags zuerst, dann weisen sie sie zu.
- Unbekannte Tag-Namen in `PUT /posts/{id}/tags` werden stillschweigend ignoriert. Dies vermeidet einen Round-Trip zur Vorab-Validierung von Namen.
- Tag-Namen sind in Antworten alphabetisch sortiert für deterministische Ausgabe.
- `GET /tags/{name}/posts` gibt 404 zurück, wenn der Tag nicht existiert, und unterscheidet so „Tag unbekannt" von „Tag existiert, aber hat keine Beiträge" (was 200 mit leerem Array zurückgibt).
