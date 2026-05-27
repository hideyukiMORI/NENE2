# Implementierungsleitfaden für Content-Versionierung

## Überblick

Dieser Leitfaden erklärt, wie mit NENE2 Content-Versionierung (vollständige Verlaufsprotokollierung, Referenzierung bestimmter Versionen, Rollback) implementiert wird.
Alle Versionen von Artikeländerungen werden append-only gespeichert und Rollbacks zu beliebigen Revisionen werden bereitgestellt.

---

## DB-Schema

```sql
CREATE TABLE articles (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    title           TEXT    NOT NULL,
    body            TEXT    NOT NULL,
    current_version INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);

CREATE TABLE article_versions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    version    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (article_id, version),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

`articles` ist die übergeordnete Tabelle mit der aktuellen neuesten Version.
`article_versions` sammelt den Inhaltänderungsverlauf **append-only**.

---

## Endpunkt-Design

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| POST | `/articles` | Artikel erstellen (als v1 initialen Commit) |
| GET | `/articles/{id}` | Neueste Version abrufen |
| PUT | `/articles/{id}` | Aktualisieren (neue Version anhängen) |
| GET | `/articles/{id}/versions` | Versionsliste |
| GET | `/articles/{id}/versions/{version}` | Bestimmte Version abrufen |
| POST | `/articles/{id}/rollback` | Zu angegebener Version zurücksetzen |

---

## Design-Kernpunkte

### Append-Only-Versionierung

Sowohl update als auch rollback **hängen eine neue Version an**. Bestehende Zeilen werden nicht überschrieben:

```php
public function update(int $id, string $title, string $body, string $now): bool
{
    $article     = $this->find($id);
    $nextVersion = (int) $article['current_version'] + 1;

    $this->db->insert(
        'UPDATE articles SET title = ?, body = ?, current_version = ?, updated_at = ? WHERE id = ?',
        [$title, $body, $nextVersion, $now, $id],
    );
    $this->db->insert(
        'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $nextVersion, $title, $body, $now],
    );
    return true;
}
```

**Vorteil**: Jede beliebige Version ist immer referenzierbar. DB-Rollback und logischer Rollback sind unabhängig voneinander.

### Rollback = Als neue Version speichern

Rollback ist die Operation "mit dem Inhalt einer bestimmten Version eine neue Version erstellen".
Dadurch **bleibt der Rollback selbst im Verlauf** und kann für Audits verwendet werden:

```
v1: Original title
v2: Modified title
v3: Original title  ← Rollback zu v1 wird hier als neue Version gespeichert
```

```php
public function rollback(int $id, int $version, string $now): bool
{
    $target      = $this->findVersion($id, $version);   // Rollback-Ziel
    $article     = $this->find($id);
    $nextVersion = (int) $article['current_version'] + 1;

    // Inhalt der Zielversion als neue Version speichern
    $this->db->insert('UPDATE articles SET title = ?, body = ?, current_version = ? ...', [...]);
    $this->db->insert('INSERT INTO article_versions ...', [$id, $nextVersion, $target['title'], $target['body'], $now]);
    return true;
}
```

### Versionsliste ohne Body

Die Listen-API gibt nur Metadaten ohne `body` zurück. Beim Abrufen einer bestimmten Version wird `body` einbezogen:

```
GET /articles/{id}/versions → [{version: 1, title: "...", created_at: "..."}, ...]
GET /articles/{id}/versions/1 → {version: 1, title: "...", body: "...", created_at: "..."}
```

### PHPStan: Nullable-Rückgabewert und null-Prüfungs-Konsistenz

Wenn nach einem Rollback `find()` erneut aufgerufen wird, kann PHPStan die null-Prüfung als "immer wahr" betrachten.
Das Problem lässt sich lösen, indem `formatArticle(?array)` so gestaltet wird, dass null akzeptiert wird, ohne `assert` zu benötigen:

```php
// Falsch: assert wird von PHPStan als "immer wahr" betrachtet
$article = $this->repo->find($id);
assert($article !== null);
return $this->json->create($this->formatArticle($article));

// Richtig: formatArticle so gestalten, dass null akzeptiert wird
return $this->json->create(array_merge($this->formatArticle($this->repo->find($id)), ['rolled_back_from' => $version]));
```

---

## Beispielantworten

### POST /articles

```json
{
  "id": 1,
  "title": "My Post",
  "body": "Hello world",
  "current_version": 1,
  "created_at": "2026-01-01T00:00:00Z",
  "updated_at": "2026-01-01T00:00:00Z"
}
```

### GET /articles/{id}/versions

```json
{
  "versions": [
    {"id": 1, "article_id": 1, "version": 1, "title": "My Post", "created_at": "..."},
    {"id": 2, "article_id": 1, "version": 2, "title": "Updated", "created_at": "..."}
  ],
  "count": 2
}
```

### POST /articles/{id}/rollback

```json
{
  "id": 1,
  "title": "My Post",
  "current_version": 3,
  "rolled_back_from": 1
}
```

---

## Referenzimplementierung

`../NENE2-FT/contentvlog/` — FT162 Field Trial (18 Tests, Append-Only-Verlauf, Rollback)
