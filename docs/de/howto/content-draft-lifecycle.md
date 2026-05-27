# How-to: Content-Entwurfs-Lebenszyklus (Entwurf → Veröffentlicht → Archiviert) mit NENE2

Diese Anleitung führt durch den Aufbau eines Artikelverwaltungssystems mit einer Entwurf/Veröffentlichung/Archivierung-Zustandsmaschine, bei der nur der Autor Zustandsübergänge auslösen kann und nur veröffentlichte Artikel für Leser sichtbar sind.

**Field Trial**: FT142  
**NENE2-Version**: ^1.5  
**Behandelte Themen**: Zustandsmaschine mit Enum, Übergangswächter, Autor-Eigentumsrecht-Prüfung, statusgefiltertes Öffentlichkeitslistung, Sortierstabilität bei gleicher Sekunde

---

## Was wir bauen

- `POST /articles` — Artikel erstellen (startet immer als `draft`)
- `GET /articles` — Nur veröffentlichte Artikel auflisten
- `GET /articles/{id}` — Artikel abrufen (Autor sieht jeden Status; andere sehen nur `published`)
- `PUT /articles/{id}` — Artikel bearbeiten (nur Entwurf, nur Autor)
- `POST /articles/{id}/publish` — Übergang `draft → published` (nur Autor)
- `POST /articles/{id}/archive` — Übergang `published → archived` (nur Autor)

---

## Datenbankschema

```sql
CREATE TABLE articles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id    INTEGER NOT NULL,
    title        TEXT    NOT NULL,
    body         TEXT    NOT NULL DEFAULT '',
    status       TEXT    NOT NULL DEFAULT 'draft',
    published_at TEXT,
    archived_at  TEXT,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL,
    CHECK (status IN ('draft', 'published', 'archived')),
    FOREIGN KEY (author_id) REFERENCES users(id)
);
```

`published_at` und `archived_at` sind nullable — sie werden nur beim entsprechenden Übergang gesetzt.

---

## ArticleStatus-Enum mit Übergangswächtern

```php
enum ArticleStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Archived  = 'archived';

    public function canEdit(): bool
    {
        return $this === self::Draft;
    }

    public function canPublish(): bool
    {
        return $this === self::Draft;
    }

    public function canArchive(): bool
    {
        return $this === self::Published;
    }
}
```

Der Handler liest den aktuellen Status, ruft die Wächtermethode auf und gibt 422 zurück, wenn der Übergang ungültig ist:

```php
$status = ArticleStatus::tryFrom($article['status']) ?? ArticleStatus::Draft;

if (!$status->canPublish()) {
    return $this->responseFactory->create(['error' => 'only draft articles can be published'], 422);
}
```

Gültige Übergänge:
- `draft → published` (via publish)
- `published → archived` (via archive)
- Es gibt keinen Übergang zurück zum Entwurf.

---

## Autor-Sichtbarkeit — Entwurf für andere verborgen

Nicht-Autoren können Entwürfe nicht lesen. 404 zurückgeben (nicht 403), um nicht preiszugeben, dass der Artikel existiert:

```php
if ($article['status'] !== 'published' && $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'article not found'], 404);
}
```

403 zurückzugeben würde bestätigen, dass der Artikel existiert. 404 ist die richtige Wahl für Inhalte, die noch nicht öffentlich sind.

---

## Sortierstabilität bei gleicher Sekunde

Wenn mehrere Artikel innerhalb derselben Sekunde veröffentlicht werden, ergibt `ORDER BY published_at DESC` allein eine nicht-deterministische Reihenfolge. `id DESC` als Tiebreaker hinzufügen:

```sql
SELECT ... FROM articles WHERE status = 'published' ORDER BY published_at DESC, id DESC
```

Eine höhere `id` bedeutet später erstellt, wodurch innerhalb derselben Sekunde effektiv nach Einfüge-Reihenfolge sortiert wird.

---

## Häufige Fehler

| Fehler | Lösung |
|--------|--------|
| 403 für Nicht-Autor-Entwurfslesungen zurückgeben | 404 zurückgeben — verhindert Inhalts-Existenz-Leck |
| `published → draft`-Wiedereröffnung erlauben | `canEdit()` gibt false zurück, außer bei `Draft`; kein "Depublizieren"-Endpunkt |
| Bereits veröffentlichten Artikel veröffentlichen | `canPublish()` gibt false für `Published` zurück → 422 |
| Entwurf archivieren | `canArchive()` gibt false zurück, außer bei `Published` → 422 |
| Nicht-deterministische Listen-Reihenfolge bei gleichem Zeitstempel | `id DESC` als sekundäre Sortierung hinzufügen |
