# How-to: Medien-Watchlist-API

> **FT-Referenz**: FT59 (`NENE2-FT/watchlog`) — Medien-Watch-List-API

Demonstriert eine persönliche Medien-Watchlist mit backed String-Enums für Status und Typ, optionalen nullable-Feldern mit `array_key_exists`, Archivieren/Wiederherstellen über POST-Aktionsendpunkte und einer 1–5-Integer-Bewertung. Alle Status- und Typvalidierungen verwenden PHPs `BackedEnum::tryFrom()`, um sicherzustellen, dass nur bekannte Werte akzeptiert werden.

---

## Routen

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `GET`    | `/watch` | Einträge auflisten (gefiltert und paginiert) |
| `POST`   | `/watch` | Einen Eintrag zur Watchlist hinzufügen |
| `GET`    | `/watch/{id}` | Einen einzelnen Eintrag abrufen |
| `PATCH`  | `/watch/{id}/status` | Status aktualisieren (und optional Bewertung/Notiz) |
| `POST`   | `/watch/{id}/archive` | Eintrag ins Archiv verschieben |
| `POST`   | `/watch/{id}/restore` | Einen archivierten Eintrag wiederherstellen |
| `DELETE` | `/watch/{id}` | Einen Eintrag dauerhaft löschen |

---

## Backed-Enum-Validierung

Status und Medientyp werden mit `BackedEnum::tryFrom()` validiert. Das Enum dient auch als Typ bei der Serialisierung, sodass der in die DB geschriebene String-Wert und der String-Wert in der JSON-Antwort automatisch synchron bleiben.

```php
enum WatchStatus: string
{
    case WantToWatch = 'want-to-watch';
    case Watching    = 'watching';
    case Completed   = 'completed';
    case Dropped     = 'dropped';
}

enum MediaType: string
{
    case Movie = 'movie';
    case Tv    = 'tv';
}
```

Im Controller gibt `tryFrom()` `null` für unbekannte Werte zurück, was auf 422 abgebildet wird:

```php
$statusRaw = isset($body['status']) && is_string($body['status']) ? $body['status'] : null;
$status    = $statusRaw !== null ? WatchStatus::tryFrom($statusRaw) : null;

if ($statusRaw === null) {
    $errors[] = new ValidationError('status', 'status is required.', 'required');
} elseif ($status === null) {
    $errors[] = new ValidationError('status', 'Invalid status value.', 'invalid_value');
}
```

Die zweistufige Prüfung unterscheidet "Feld fehlt" (required) von "Feld vorhanden, aber ungültig" (invalid_value), was bessere Fehlermeldungen erzeugt.

---

## Auflistung mit enum-typisierten Filtern

Query-Parameter werden über `QueryStringParser` geparst, dann über `tryFrom()` validiert:

```php
$statusRaw = QueryStringParser::string($request, 'status');   // null wenn fehlt
$status    = $statusRaw !== null ? WatchStatus::tryFrom($statusRaw) : null;

if ($statusRaw !== null && $status === null) {
    $errors[] = new ValidationError('status', 'Invalid status value.', 'invalid_value');
}
```

Dieses Muster — parsen, Enum-Konvertierung versuchen, validieren — hält Routing-Logik außerhalb des Domänen-Codes. Das Repository akzeptiert `?WatchStatus` und `?MediaType` und filtert entsprechend.

**Unterstützte Filter**:
- `?status=watching` — nach Status filtern
- `?media_type=movie` — nach Medientyp filtern
- `?include_archived=1` — archivierte Einträge einschließen (standardmäßig ausgeschlossen)
- `?limit=20&offset=0` — Paginierung

---

## Nullable-Felder mit `array_key_exists`

`rating` und `note` sind nullable — Aufrufer können sie explizit auf `null` setzen, um sie zu löschen. Die Verwendung von `isset()` würde ein explizit gesendetes `null` übersehen. `array_key_exists()` verwenden:

```php
// ✓ Korrekt: unterscheidet fehlend von explizit null
$rating = array_key_exists('rating', $body) ? $body['rating'] : null;

// ✗ Falsch: array_key_exists($body, 'rating') verschluckt absichtliches null
if ($rating !== null) {
    if (!is_int($rating) || $rating < 1 || $rating > 5) {
        $errors[] = new ValidationError('rating', 'rating must be an integer from 1 to 5.', 'out_of_range');
    }
}
```

`is_int($rating)` lehnt JSON-Floats (`4.0` → PHP `float`) und Strings (`"4"`) ab. Nur ein JSON-Integer-Literal (`4`) besteht die strenge Typprüfung.

---

## Archivieren/Wiederherstellen über POST-Aktionsendpunkte

Archivieren und Wiederherstellen sind Mutationen (sie ändern den Zustand und zeichnen einen Zeitstempel auf), verwenden also `POST`, nicht `DELETE` oder `PATCH`. Dies folgt dem Aktionsendpunkt-Muster:

```php
// POST /watch/{id}/archive
private function archive(ServerRequestInterface $request): ResponseInterface
{
    $id    = (int) ($request->getAttribute(Router::PARAMETERS_ATTRIBUTE)['id'] ?? 0);
    $entry = $this->repository->archive($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $this->json->create($this->serialize($entry));
}

// POST /watch/{id}/restore
private function restore(ServerRequestInterface $request): ResponseInterface
{
    $id    = (int) ($request->getAttribute(Router::PARAMETERS_ATTRIBUTE)['id'] ?? 0);
    $entry = $this->repository->restore($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $this->json->create($this->serialize($entry));
}
```

`archive()` setzt `archived_at` auf den aktuellen Zeitstempel; `restore()` setzt es auf `null` zurück. Der Listenendpunkt versteckt archivierte Einträge standardmäßig (`include_archived=false`).

Warum `POST` und nicht `DELETE` für Archivieren? `DELETE` impliziert permanentes Entfernen. Archivieren ist eine Soft-Zustandsänderung — der Eintrag bleibt in der DB und ist wiederherstellbar. Die Benennung der Endpunkte nach der Aktion (`/archive`, `/restore`) macht die Absicht explizit.

---

## Schema: CHECK-Constraints entsprechen Enum-Werten

```sql
CREATE TABLE watch_entries (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT NOT NULL,
    media_type  TEXT NOT NULL CHECK(media_type IN ('movie', 'tv')),
    status      TEXT NOT NULL DEFAULT 'want-to-watch'
                              CHECK(status IN ('want-to-watch', 'watching', 'completed', 'dropped')),
    rating      INTEGER CHECK(rating IS NULL OR (rating >= 1 AND rating <= 5)),
    note        TEXT NOT NULL DEFAULT '',
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL,
    archived_at TEXT
);
```

DB `CHECK`-Constraints spiegeln die Enum-Fälle wider — wenn ein neuer Status zum Enum hinzugefügt wird, ohne den `CHECK` zu aktualisieren, schlägt der Insert auf der DB-Ebene fehl. Beide synchron halten: den neuen Fall zum Enum, dem `CHECK` und einer Migration hinzufügen.

`rating CHECK(rating IS NULL OR ...)` erlaubt korrekt, dass die Spalte `NULL` ist, während der 1–5-Bereich erzwungen wird, wenn ein Wert vorhanden ist.

`archived_at TEXT` (nullable) dient als Archivierungs-Flag: `NULL` = aktiv, nicht-null = archiviert. Das ist das minimale Soft-Archiv-Muster — keine separate `is_archived BOOLEAN`-Spalte nötig.

---

## Indizes für Listen-Performance

```sql
CREATE INDEX idx_watch_status      ON watch_entries (status);
CREATE INDEX idx_watch_archived_at ON watch_entries (archived_at);
```

`idx_watch_archived_at` unterstützt den häufigen `WHERE archived_at IS NULL`-Filter (aktive Einträge). SQLite kann diesen Index für `IS NULL`-Bedingungen über ein Partial-Index-Muster verwenden, aber ein einfacher Index ist für die meisten Watchlists ausreichend.

---

## Serialisierung

```php
/** @return array<string, mixed> */
private function serialize(WatchEntry $entry): array
{
    return [
        'id'          => $entry->id,
        'title'       => $entry->title,
        'media_type'  => $entry->mediaType->value,  // enum → string
        'status'      => $entry->status->value,      // enum → string
        'rating'      => $entry->rating,             // int|null
        'note'        => $entry->note,
        'created_at'  => $entry->createdAt,
        'updated_at'  => $entry->updatedAt,
        'archived_at' => $entry->archivedAt,         // string|null
    ];
}
```

`->value` auf einem backed-Enum gibt den String-Case-Wert zurück (z.B. `'want-to-watch'`). Enums auf diese Weise serialisieren, nicht `->name` aufrufen — der Name ist der PHP-Identifier (`WantToWatch`), nicht der API-Vertragswert.

---

## Verwandte Anleitungen

- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — Zustandsmaschine mit Statusübergängen
- [`soft-delete.md`](soft-delete.md) — Soft Delete mit `deleted_at`-Zeitstempel
- [`implement-patch-endpoint.md`](implement-patch-endpoint.md) — partielle Updates mit `array_key_exists`
- [`add-custom-route.md`](add-custom-route.md) — POST-Aktionsendpunkt-Muster (`/archive`, `/restore`, `/publish`)
