# Wartelisten-Verwaltung

Implementierungsanleitung für positionsbasierte Wartelisten (Warteschlangen).
Erläutert dynamische Positionsberechnung, Zustandsmaschine, IDOR-Prävention und Admin-exklusive Endpunkte.

## Überblick

- Benutzer treten der Warteliste bei (optionale Notiz)
- **Dynamische Positionsberechnung**: Position wird nicht in der DB gespeichert, sondern per `COUNT(*)` berechnet
- Zustandsmaschine: `waiting` → `approved` / `declined` (einseitig, nicht umkehrbar)
- Nur wartende Benutzer können austreten (`approved`/`declined` danach nicht mehr möglich)
- Admin listet alle Einträge, genehmigt und lehnt ab (`X-Admin-Key`-Header)
- Benutzerantwort enthält keine `user_id` (IDOR-Prävention)

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|--------------|
| `POST` | `/waitlist` | `X-User-Id` | Warteliste beitreten |
| `GET` | `/waitlist/me` | `X-User-Id` | Eigenen Eintrag und Position abrufen |
| `DELETE` | `/waitlist/me` | `X-User-Id` | Warteliste verlassen |
| `GET` | `/waitlist` | `X-Admin-Key` | Alle Einträge auflisten (Admin) |
| `POST` | `/waitlist/{id}/approve` | `X-Admin-Key` | Eintrag genehmigen |
| `POST` | `/waitlist/{id}/decline` | `X-Admin-Key` | Eintrag ablehnen |

## Datenbankdesign

```sql
CREATE TABLE IF NOT EXISTS waitlist_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL UNIQUE,
    status     TEXT    NOT NULL DEFAULT 'waiting',
    note       TEXT,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

`UNIQUE` auf `user_id` stellt sicher, dass ein Benutzer nur einen Eintrag hat.
Keine Positionsspalte (dynamische Berechnung).

## Dynamische Positionsberechnung

Position eines wartenden Eintrags wird relativ zur `id`-Reihenfolge berechnet:

```sql
SELECT COUNT(*) FROM waitlist_entries
WHERE status = 'waiting' AND id <= :id
```

Vorteile:
- Kein UPDATE aller Einträge bei Austritt/Genehmigung/Ablehnung erforderlich
- Keine Schreibkonflikte
- Für Einträge mit einem anderen Status als `waiting` wird `null` zurückgegeben

```php
public function positionOf(WaitlistEntry $entry): ?int
{
    if ($entry->status !== WaitlistStatus::Waiting) {
        return null;
    }

    $stmt = $this->pdo->prepare(
        "SELECT COUNT(*) FROM waitlist_entries
         WHERE status = 'waiting' AND id <= :id",
    );
    $stmt->execute(['id' => $entry->id]);

    return (int) $stmt->fetchColumn();
}
```

## Zustandsmaschine

```
waiting ──→ approved
        └─→ declined
```

- `waiting` kann nur zu `approved` oder `declined` wechseln
- Nach Erreichen eines Terminal-Zustands keine Änderung mehr möglich (`isTerminal()`)
- Nur wartende Benutzer können über `DELETE /waitlist/me` austreten

```php
enum WaitlistStatus: string
{
    case Waiting  = 'waiting';
    case Approved = 'approved';
    case Declined = 'declined';

    public function isTerminal(): bool
    {
        return $this !== self::Waiting;
    }
}
```

## IDOR-Prävention

Benutzerendpunkte (`/waitlist/me`) rufen über den `X-User-Id`-Header **nur den eigenen Eintrag** ab.
Es gibt keinen Pfad, über den eine fremde `user_id` übergeben werden könnte, und die Antwort enthält keine `user_id`.

```php
/** Benutzerantwort (ohne user_id) */
public function toPublicArray(): array
{
    return [
        'id'         => $this->id,
        'status'     => $this->status->value,
        'note'       => $this->note,
        'created_at' => $this->createdAt,
    ];
}

/** Admin-Antwort (mit user_id) */
public function toAdminArray(): array
{
    return [
        'id'         => $this->id,
        'user_id'    => $this->userId,
        'status'     => $this->status->value,
        'note'       => $this->note,
        'created_at' => $this->createdAt,
        'updated_at' => $this->updatedAt,
    ];
}
```

## Admin-Authentifizierung

Den `X-Admin-Key`-Header mit `hash_equals()` in Konstantzeit vergleichen.
Leerer adminKey gibt immer `false` zurück (Fail-Closed):

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') {
        return false; // fail-closed
    }

    $provided = $request->getHeaderLine('X-Admin-Key');

    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

## Routenreihenfolge

`GET /waitlist/me` muss **vor** `GET /waitlist` registriert werden.
Andernfalls wird `me` möglicherweise als `{id}` erfasst:

```php
$this->router->post('/waitlist',            $this->handleJoin(...));
$this->router->get('/waitlist/me',          $this->handleMe(...));      // vor /waitlist registrieren
$this->router->delete('/waitlist/me',       $this->handleLeave(...));
$this->router->get('/waitlist',             $this->handleAdminList(...));
$this->router->post('/waitlist/{id}/approve', $this->handleApprove(...));
$this->router->post('/waitlist/{id}/decline', $this->handleDecline(...));
```

## X-User-Id-Validierung

Schutz vor Integer-Überlauf, Null, negativen Zahlen und Nicht-Ziffern:

```php
private function resolveUserId(ServerRequestInterface $request): ?int
{
    $raw = $request->getHeaderLine('X-User-Id');

    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
        return null;
    }

    $id = (int) $raw;

    return $id > 0 ? $id : null;
}
```

## Sicherheitspunkte

| Bedrohung | Gegenmaßnahme |
|-----------|---------------|
| IDOR | `/waitlist/me` nur für sich selbst, keine `user_id` in der Antwort |
| Admin-Key-Abhören | `hash_equals()` für Konstantzeit-Vergleich |
| Integer-Überlauf | `strlen > 18`-Guard |
| Doppelte Teilnahme | `UNIQUE(user_id)`-Constraint → 409 |
| Ungültige Zustandsübergänge | `isTerminal()` verhindert Änderungen nach Terminal-Zustand |
| SQL-Injection | PDO Prepared Statements |

## Antwortbeispiele

```json
// POST /waitlist (201)
{
    "entry": { "id": 1, "status": "waiting", "note": "VIP-Anfrage", "created_at": "..." },
    "position": 1
}

// GET /waitlist/me — status approved (200)
{
    "entry": { "id": 1, "status": "approved", "note": "VIP-Anfrage", "created_at": "..." },
    "position": null
}

// GET /waitlist (admin, 200)
{
    "data": [
        { "id": 1, "user_id": 101, "status": "approved", "note": "VIP-Anfrage", ... },
        { "id": 2, "user_id": 102, "status": "waiting",  "note": null,          ... }
    ],
    "total": 2
}
```

## Verwandte Anleitungen

- [System-Ankündigung-Verwaltung](system-announcement-management.md) — Admin-Key-Authentifizierungsmuster (ähnliches `hash_equals()`)
- [Datenschutz-Einwilligungsverwaltung](privacy-consent-management.md) — UPSERT und idempotente Operationen
- [Soft-Delete](soft-delete.md) — Lösch-Flag-Muster (Austritt ist physisches Löschen)
- [Doppelbuchung verhindern](prevent-double-booking.md) — Konfliktvermeidung durch UNIQUE-Constraint
