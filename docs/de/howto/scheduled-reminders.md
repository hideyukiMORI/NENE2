# How-to: Geplante Erinnerungs-API

> **FT-Referenz**: FT235 (`NENE2-FT/reminderlog`) — Geplante Erinnerungs-API

Demonstriert eine Erinnerungs-Planungs-API mit zeitzonenbewusster Zukunftsdatum-Validierung,
leichter Pro-Anfrage-Benutzeridentifikation via Header, IDOR-Prävention durch
eigentümerschafts-begrenzte Abfragen und einer 404/409-Unterscheidung beim Stornieren einer Erinnerung.

---

## Routen

| Methode  | Pfad                        | Beschreibung                                           |
|---------|-----------------------------|-------------------------------------------------------|
| `POST`  | `/reminders`                | Erinnerung erstellen (zukünftiges `remind_at` erforderlich) |
| `GET`   | `/reminders`                | Erinnerungen für Aufrufer auflisten (nach Status filterbar) |
| `PATCH` | `/reminders/{id}/cancel`    | Ausstehende Erinnerung stornieren |

Alle Routen erfordern den `X-User-Id`-Header.

---

## Leichte Benutzeridentifikation via Header

Statt Bearer JWT verwendet diese API einen `X-User-Id`-Integer-Header als minimalen
Authentifizierungs-/Identifikationsmechanismus:

```php
$userId = V::userId($request->getHeaderLine('X-User-Id'));

if ($userId === null) {
    return $this->responseFactory->create(
        ['error' => 'X-User-Id header must be a positive integer.'],
        401,
    );
}
```

`V::userId()` validiert den Header-Wert:

```php
public static function userId(string $header): ?int
{
    // ctype_digit('') === false — leere Zeichenkette bereits abgelehnt.
    if (!ctype_digit($header) || strlen($header) > 18) {
        return null;
    }

    $id = (int) $header;

    return $id > 0 ? $id : null;
}
```

Schlüsseleigenschaften:
- `ctype_digit()` — ReDoS-immun, lehnt `0`, `-1`, `1.5`, `abc`, leere Zeichenkette ab.
- `strlen > 18` — Überlauf-Guard vor `(int)`-Cast (PHP_INT_MAX hat 19 Stellen).
- `$id > 0` — lehnt die geparste Integer-Null ab.

---

## Zukunftsdatum-Validierung (zeitzonenbewusst)

`remind_at` muss ein gültiger ISO 8601-Datetime mit explizitem Timezone-Offset **und**
muss streng in der Zukunft relativ zu jetzt sein:

```php
$now      = (new DateTimeImmutable())->format(DATE_ATOM);
$remindAt = V::futureDatetime($rawRemindAt, $now);

if ($remindAt === null) {
    return $this->responseFactory->create(
        ['error' => 'remind_at must be a valid ISO 8601 datetime with timezone offset and must be in the future.'],
        422,
    );
}
```

`V::futureDatetime()` kombiniert zwei Prüfungen:

```php
public static function futureDatetime(mixed $raw, string $now): ?string
{
    $dt = self::isoDatetime($raw);   // Schritt 1: Format + Bereichsvalidierung

    if ($dt === null) {
        return null;
    }

    // Schritt 2: Zeitzonenbewusste Zukunftsprüfung
    $dtObj  = DateTimeImmutable::createFromFormat(DATE_ATOM, $dt);
    $nowObj = DateTimeImmutable::createFromFormat(DATE_ATOM, $now);

    if ($dtObj === false || $nowObj === false) {
        return null;
    }

    return $dtObj > $nowObj ? $dt : null;  // Objektvergleich normalisiert auf UTC
}
```

---

## IDOR-Prävention: Eigentümerschafts-begrenzte Suche

Alle Operationen, die eine bestimmte Erinnerung berühren, verwenden `WHERE id = ? AND user_id = ?`:

```php
public function findForUser(int $id, int $userId): ?Reminder
{
    $stmt = $this->pdo->prepare('SELECT * FROM reminders WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $this->hydrate($row) : null;
}
```

Wenn die Erinnerung einem anderen Benutzer gehört, gibt `findForUser()` `null` zurück — der Aufrufer
erhält `404 Not Found`, ununterscheidbar von "Erinnerung existiert nicht". `403 Forbidden`
zurückzugeben würde bestätigen, dass die ID existiert, was Enumeration-Informationen leckt.

---

## 404 vs 409: Zuerst-Abrufen-Stornieren

Der Stornieren-Handler ruft die Erinnerung ab, bevor der Status geprüft wird. Dieser Zwei-Schritt-Ansatz
ermöglicht den korrekten HTTP-Status für jeden Fehlerfall:

```php
// Zuerst abrufen, um 404 (nicht gefunden/falscher Eigentümer) von 409 (falscher Status) zu unterscheiden
$reminder = $this->repository->findForUser($id, $userId);

if ($reminder === null) {
    return $this->responseFactory->create(['error' => 'Reminder not found.'], 404);
}

if ($reminder->status !== ReminderStatus::Pending) {
    return $this->responseFactory->create(
        ['error' => sprintf('Cannot cancel a reminder with status "%s".', $reminder->status->value)],
        409,
    );
}

$this->repository->cancel($id, $userId);
```

---

## Schema

```sql
CREATE TABLE reminders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    message    TEXT    NOT NULL,
    remind_at  TEXT    NOT NULL,  -- ISO 8601 mit Timezone-Offset, as-is gespeichert
    status     TEXT    NOT NULL DEFAULT 'pending',
    created_at TEXT    NOT NULL,
    CHECK (status IN ('pending', 'triggered', 'cancelled'))
);

CREATE INDEX idx_reminders_user   ON reminders (user_id, id);
CREATE INDEX idx_reminders_status ON reminders (status, id);
```

---

## Status-Enum

```php
enum ReminderStatus: string
{
    case Pending   = 'pending';
    case Triggered = 'triggered';
    case Cancelled = 'cancelled';
}
```

Nur `pending`-Erinnerungen können storniert werden (`409` andernfalls). `triggered` wird durch
einen Hintergrundjob gesetzt, wenn die Erinnerung auslöst — diese API enthält nicht den Auslöser-
Endpunkt, der auf einem geplanten Task außerhalb des HTTP-Servers laufen würde.

---

## Verwandte Handbücher

- [`iso-datetime-validation.md`](iso-datetime-validation.md) — ISO 8601-Datetime-Validierungsmuster
- [`content-scheduling.md`](content-scheduling.md) — geplante Veröffentlichung mit zukünftigem `publish_at`
- [`approval-workflow.md`](approval-workflow.md) — 404/409-Unterscheidung bei Status-Übergängen
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR-Präventionsmuster
