# How-to: Ressourcenreservierung / Zeitfenster-Buchungs-API

Diese Anleitung zeigt, wie ein Zeitfenster-Buchungssystem mit Überlappungsprävention in NENE2 gebaut wird.
Muster demonstriert durch das **reservationlog** Feldversuch (FT216).

## Funktionen

- Benannte Ressourcen erstellen (Besprechungsräume, Ausrüstung usw.) — nur Admin
- Zeitfenster buchen mit automatischer Überlappungserkennung
- Buchungen pro Ressource (Admin) oder pro Benutzer (selbst) auflisten
- Buchungen mit Eigentümerverifizierung stornieren
- Öffentliche Antworten schließen `user_id` aus (IDOR-Prävention)
- Admin-Ansicht enthält `user_id` für Prüfung

## Schema

```sql
CREATE TABLE IF NOT EXISTS resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    starts_at   TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at     TEXT    NOT NULL,   -- ISO 8601 UTC
    note        TEXT,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
);

-- Index für schnelle Überlappungsabfragen
CREATE INDEX IF NOT EXISTS idx_bookings_resource_time
    ON bookings (resource_id, starts_at, ends_at);
```

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---|---|---|---|
| `POST` | `/resources` | Admin | Ressource erstellen |
| `GET` | `/resources/{id}/bookings` | Admin | Alle Buchungen für Ressource auflisten |
| `POST` | `/resources/{id}/book` | Benutzer | Zeitfenster buchen |
| `GET` | `/bookings` | Benutzer | Eigene Buchungen auflisten |
| `DELETE` | `/bookings/{id}` | Benutzer | Eigene Buchung stornieren |

## Überlappungserkennung

Zwei Zeitbereiche `[A.start, A.end)` und `[B.start, B.end)` überlappen sich wenn:

```
A.start < B.end AND A.end > B.start
```

Das behandelt korrekt alle Überlappungsfälle (enthält, überschneidet, identisch) während angrenzende Slots erlaubt werden (A.end = B.start ist OK — halboffene Intervallsemantik).

```sql
SELECT COUNT(*) FROM bookings
WHERE resource_id = :rid
  AND starts_at < :ends_at
  AND ends_at   > :starts_at
```

```php
public function book(int $resourceId, int $userId, string $startsAt, string $endsAt, ?string $note): ?Booking
{
    $overlap = $this->countOverlaps($resourceId, $startsAt, $endsAt, excludeId: null);
    if ($overlap > 0) {
        return null; // → 409 Conflict
    }
    // ... INSERT
}
```

## Value Objects

Readonly Value Objects für Domänenklarheit verwenden:

```php
final readonly class Booking
{
    public function __construct(
        public int     $id,
        public int     $resourceId,
        public int     $userId,
        public string  $startsAt,
        public string  $endsAt,
        public ?string $note,
        public string  $createdAt,
    ) {}

    /** Öffentliche Ansicht: schließt user_id aus (IDOR-Prävention) */
    public function toPublicArray(): array { ... }

    /** Admin-Ansicht: enthält user_id für Prüfung */
    public function toAdminArray(): array { ... }
}
```

## IDOR-Prävention

Buchungen exponieren öffentliche und Admin-Ansichten mit verschiedenen Feldern:

```php
// Benutzer: GET /bookings — öffentliche Ansicht (kein user_id)
return $this->responseFactory->create([
    'data'  => array_map(fn(Booking $b) => $b->toPublicArray(), $bookings),
    'total' => count($bookings),
]);

// Admin: GET /resources/{id}/bookings — Admin-Ansicht (enthält user_id)
return $this->responseFactory->create([
    'data'  => array_map(fn(Booking $b) => $b->toAdminArray(), $bookings),
    'total' => count($bookings),
]);
```

Stornieren gibt 403 (nicht 404) zurück, wenn ein Benutzer versucht, die Buchung eines anderen zu stornieren, da die Buchungs-ID bereits sichtbar ist (keine versteckte Existenz):

```php
/** @return 'cancelled'|'not_found'|'not_owner' */
public function cancel(int $id, int $userId): string
{
    $booking = $this->findBookingById($id);
    if ($booking === null) return 'not_found';     // → 404
    if ($booking->userId !== $userId) return 'not_owner'; // → 403
    // DELETE ...
    return 'cancelled'; // → 200
}
```

## Sicherheitsmuster

- **Admin fail-closed**: `if ($this->adminKey === '') return false;` vor `hash_equals()`
- **`ctype_digit()`**: ReDoS-sichere Integer-Validierung für Pfad-IDs
- **ISO 8601-Validierung**: Regex-Muster + lexikographischer Vergleich (funktioniert in UTC)
- **Notizlängen-Guard**: `mb_strlen($note) > 500` gibt 422 zurück
- **Kaskaden-Löschen**: `ON DELETE CASCADE` stellt sicher, dass Buchungen mit der Ressource entfernt werden

## VULN + ATK-Assessment (FT216)

Dieser FT besteht vollständige VULN-A bis VULN-L und ATK-01 bis ATK-12 Auswertungen:

- **VULN-B**: Kein Mass Assignment — Ressourcen/Buchungsfelder werden explizit gebunden
- **VULN-C**: Stornieren gibt 403 für falschen Eigentümer zurück; Ressourcen/Buchungssuchen verwenden typisierte IDs
- **VULN-D**: Admin fail-closed — leerer Admin-Key gibt immer false zurück
- **VULN-F**: ISO 8601-Regex verhindert Datetime-Injection
- **VULN-G**: `ctype_digit()` sichert alle Integer-Pfadparameter ab
- **ATK-01**: SQL-Injection durch parametrisierte Abfragen geblockt
- **ATK-02/03**: Integer-Überlauf in IDs durch `strlen > 18`-Guard geblockt
- **ATK-06**: Authentifizierungs-Bypass durch fail-closed Admin-Prüfung geblockt
- **ATK-09**: Überlappungslogik verhindert korrekt Doppelbuchungen
