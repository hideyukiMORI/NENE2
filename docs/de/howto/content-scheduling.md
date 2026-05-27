# Content-Planung — Zeitbasiertes Veröffentlichen mit Lebenszyklus-Zuständen

Inhalte zur Veröffentlichung zu einem zukünftigen Zeitpunkt planen mit einer `publish_at`-Spalte, einer Zustandsmaschine (`draft → scheduled → published → archived`) und einem **Publish-Due-Trigger**-Endpunkt, den ein Cron-Job aufruft, um fällige Artikel umzuschalten.

**Referenzimplementierung:** `FT172 pubschedulelog` in
[hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples)

---

## Status-Lebenszyklus

```
draft ──┬──► scheduled ──► published ──► archived
        │                               ▲
        └───────────────────────────────┘
        (auch: scheduled → draft via unschedule)
```

| Von | Erlaubte Übergänge |
|-----|--------------------|
| `draft` | `scheduled`, `published`, `archived` |
| `scheduled` | `published`, `draft`, `archived` |
| `published` | `archived` |
| `archived` | *(keine)* |

---

## Schema

```sql
CREATE TABLE articles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id    INTEGER NOT NULL,
    title        TEXT    NOT NULL,
    body         TEXT    NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'draft',
    -- 'draft' | 'scheduled' | 'published' | 'archived'
    publish_at   TEXT,    -- ISO 8601; gesetzt wenn geplant; sonst NULL
    published_at TEXT,    -- gesetzt wenn tatsächlich veröffentlicht
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

---

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|--------------|
| `POST` | `/articles` | X-User-Id | Entwurf erstellen |
| `GET` | `/articles` | optional | Auflisten (`?status=published` ist öffentlich; andere Status erfordern Auth + nur eigene Artikel) |
| `GET` | `/articles/{id}` | optional | Einen Artikel abrufen (veröffentlicht = öffentlich, Entwurf/geplant = nur Eigentümer) |
| `PUT` | `/articles/{id}` | X-User-Id | Titel/Body aktualisieren (nur Entwurf oder geplant) |
| `POST` | `/articles/{id}/schedule` | X-User-Id | `publish_at` setzen → wechselt zu `scheduled` |
| `POST` | `/articles/{id}/unschedule` | X-User-Id | Planung abbrechen → kehrt zu `draft` zurück |
| `POST` | `/articles/{id}/publish` | X-User-Id | Sofort veröffentlichen |
| `POST` | `/articles/{id}/archive` | X-User-Id | Archivieren |
| `POST` | `/articles/publish-due` | X-Admin-Key | Alle fälligen geplanten Artikel in Bulk veröffentlichen |

---

## Kernmuster

### Status-Enum mit Übergangswächter

```php
enum ArticleStatus: string {
    case Draft     = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived  = 'archived';

    public function canTransitionTo(self $next): bool {
        return match ($this) {
            self::Draft     => in_array($next, [self::Scheduled, self::Published, self::Archived], true),
            self::Scheduled => in_array($next, [self::Published, self::Draft, self::Archived], true),
            self::Published => $next === self::Archived,
            self::Archived  => false,
        };
    }
}
```

### Planen: Nur-Zukunft-Validierung

```php
$ts = strtotime($publishAt);
if ($ts === false || $ts === -1) {
    throw new ArticleScheduleException('publish_at is not a valid datetime.');
}
if ($ts <= strtotime($now)) {
    throw new ArticleScheduleException('publish_at must be in the future.');
}
```

### Publish-Due-Trigger (Cron-sicher, idempotent)

```php
public function publishDue(string $now): array
{
    $rows = $this->db->fetchAll(
        "SELECT id FROM articles WHERE status = ? AND publish_at <= ? ORDER BY publish_at",
        [ArticleStatus::Scheduled->value, $now],
    );

    $published = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $this->db->execute(
            'UPDATE articles SET status = ?, published_at = ?, publish_at = NULL, updated_at = ? WHERE id = ?',
            [ArticleStatus::Published->value, $now, $now, $id],
        );
        $published[] = $id;
    }

    return $published;  // list<int>
}
```

Aus einem Cron-Job jede Minute aufrufen. Idempotent: Erneutes Ausführen findet keine neuen fälligen Artikel, da `publish_at` bei der Veröffentlichung auf `NULL` gesetzt wird.

### IDOR-Prävention

Entwürfe und geplante Artikel sind **nur für Eigentümer** — 404 (nicht 403) zurückgeben, um die Existenz nicht preiszugeben:

```php
if ($article->authorId !== $actorId) {
    throw new ArticleNotFoundException($id);  // 404, nicht 403
}
```

### Admin-Schlüssel — Timing-sichere Vergleich

```php
if ($apiKey === '' || !hash_equals($expected, $apiKey)) {
    return $this->responseFactory->create(['error' => 'unauthorized'], 401);
}
```

Niemals `!==` für Secret-Vergleiche verwenden — `hash_equals()` verwenden, um Timing-Angriffe zu verhindern.

---

## Sicherheitshinweise

| Risiko | Maßnahme |
|--------|----------|
| Vergangenes `publish_at` injizieren | `strtotime($publishAt) <= strtotime($now)` → 422 |
| Benutzerübergreifende Zustandsmutation | Eigentumsrecht-Prüfung vor jedem Übergang; 404 nicht 403 |
| Autor-ID-Injection über Body | `authorId` nur aus `X-User-Id`-Header entnommen |
| Status-Injection über Body | `status`-Feld in PUT-Body wird ignoriert; Übergänge über dedizierte Aktions-Endpunkte |
| Timing-Angriff auf Admin-Schlüssel | `hash_equals()` statt `!==` |
| Enumeration unveröffentlichter Artikel | Öffentliche Auflistung filtert immer nach `status = published`; nicht-veröffentlichte erfordern Auth + nur eigene Artikel |
| Bearbeitung nach Veröffentlichung | PUT lehnt nicht-Entwurf/geplante Artikel mit 422 ab |
| Doppeltes Archivieren | Übergangswächter gibt 409 für ungültige Übergänge zurück |

---

## Cron-Integration

```bash
# /etc/cron.d/publish-due
* * * * * www-data curl -s -X POST https://api.example.com/articles/publish-due \
  -H "X-Admin-Key: $ADMIN_KEY"
```

Für höhere Workloads zur Job-Warteschlange wechseln (siehe [job-queue.md](./job-queue.md)) und den Warteschlangen-Worker `publishDue()` aufrufen lassen.

---

## Siehe auch

- [Content Draft Lifecycle](./content-draft-lifecycle.md) — Entwurf/aktiv/archiviert ohne Planung
- [Job Queue](./job-queue.md) — Hintergrundverarbeitung für hochvolumige Publish-Trigger
- [Soft Delete](./soft-delete.md) — Ergänzung zur Archivierung
- [Audit Trail](./audit-trail.md) — Aufzeichnen wer was wann veröffentlicht hat
