# Anleitung: Geplante Erinnerungs-API

> **FT-Referenz**: FT235 (`NENE2-FT/reminderlog`) — Geplante Erinnerungs-API

Demonstriert eine Erinnerungs-Planungs-API mit zeitzonenbewusster zukünftiger Datetime-Validierung,
leichter Per-Anfrage-Benutzeridentifikation über einen Header, IDOR-Prävention durch
eigentümerschaftsbeschränkte Abfragen und eine 404/409-Unterscheidung beim Stornieren einer Erinnerung.

---

## Routen

| Methode  | Pfad                        | Beschreibung                                           |
|----------|-----------------------------|--------------------------------------------------------|
| `POST`   | `/reminders`                | Erinnerung erstellen (zukünftiges `remind_at` erforderlich) |
| `GET`    | `/reminders`                | Erinnerungen des Aufrufers auflisten (nach Status filterbar) |
| `PATCH`  | `/reminders/{id}/cancel`    | Ausstehende Erinnerung stornieren                      |

Alle Routen erfordern den `X-User-Id`-Header.

---

## Leichte Benutzeridentifikation über Header

Statt Bearer-JWT verwendet diese API einen `X-User-Id`-Integer-Header als minimalen
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
    // ctype_digit('') === false — leerer String wird bereits abgelehnt.
    if (!ctype_digit($header) || strlen($header) > 18) {
        return null;
    }

    $id = (int) $header;

    return $id > 0 ? $id : null;
}
```

Wichtige Eigenschaften:
- `ctype_digit()` — ReDoS-immun, lehnt `0`, `-1`, `1.5`, `abc`, leeren String ab.
- `strlen > 18` — Überlauf-Schutz vor dem `(int)`-Cast (PHP_INT_MAX hat 19 Ziffern).
- `$id > 0` — lehnt den geparsten Integer null ab.

Für die Produktion durch JWT- oder Session-Validierung ersetzen. Das `X-User-Id`-Muster ist
für interne Dienste geeignet, wo das vorgelagerte Gateway den Benutzer bereits authentifiziert
hat und seine ID weiterleitet.

---

## Zukünftige Datetime-Validierung (zeitzonenbewusst)

`remind_at` muss ein gültiges ISO 8601-Datetime mit einem expliziten Zeitzonenoffset sein **und**
muss relativ zu jetzt strikt in der Zukunft liegen:

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
    $dt = self::isoDatetime($raw);   // Schritt 1: Format- und Bereichsvalidierung

    if ($dt === null) {
        return null;
    }

    // Schritt 2: zeitzonenbewusste Zukunftsprüfung
    $dtObj  = DateTimeImmutable::createFromFormat(DATE_ATOM, $dt);
    $nowObj = DateTimeImmutable::createFromFormat(DATE_ATOM, $now);

    if ($dtObj === false || $nowObj === false) {
        return null;
    }

    return $dtObj > $nowObj ? $dt : null;  // Objektvergleich normalisiert auf UTC
}
```

`V::isoDatetime()` führt zuerst die Formatprüfung durch:

```php
public static function isoDatetime(mixed $raw): ?string
{
    // Striktes Regex: erfordert ±HH:MM-Offset — lehnt 'Z', nur-Datum, fehlenden Offset ab.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-])(\d{2}):(\d{2})$/', $raw, $m)) {
        return null;
    }

    // Zeitzonenoffset-Bereich validieren: gültige UTC-Offsets sind −14:00 … +14:00.
    $tzHours   = (int) $m[2];
    $tzMinutes = (int) $m[3];

    if ($tzHours > 14 || $tzMinutes > 59 || ($tzHours === 14 && $tzMinutes > 0)) {
        return null;
    }
    // ... Roundtrip-Validierung für Überlaufdaten (30. Feb. usw.)
}
```

Der `DateTimeImmutable`-Objektvergleich (`>`) konvertiert beide Seiten vor dem
Vergleich auf UTC — sodass `2026-06-01T09:00:00+09:00` (00:00 UTC) korrekt mit
`2026-06-01T01:00:00+01:00` (00:00 UTC) als gleich verglichen wird.

---

## IDOR-Prävention: eigentümerschaftsbeschränkte Suche

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
erhält `404 Not Found`, nicht von „Erinnerung existiert nicht" zu unterscheiden. Die Rückgabe von
`403 Forbidden` würde bestätigen, dass die ID existiert, und Enumerationsinformationen preisgeben.

---

## 404 vs. 409: Stornierung mit vorherigem Abruf

Der Stornierungshandler ruft die Erinnerung ab, bevor er den Status prüft. Dieser Zwei-Schritte-Ansatz
ermöglicht die Rückgabe des korrekten HTTP-Status für jeden Fehlerfall:

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

Die DB-seitige Stornierung enthält die Statusprüfung als Sicherheits-Backup:

```php
public function cancel(int $id, int $userId): bool
{
    $stmt = $this->pdo->prepare(
        "UPDATE reminders SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'"
    );
    $stmt->execute([$id, $userId]);

    return $stmt->rowCount() > 0;
}
```

`WHERE status = 'pending'` im UPDATE stellt sicher, dass eine Race Condition (zwei gleichzeitige
Stornierungsanfragen) dazu führt, dass nur eine Zeile aktualisiert wird.

---

## Abfrageparameter-Validierung (`?limit=` und `?status=`)

`limit` verwendet `V::queryInt()`, das zwischen fehlendem Schlüssel (Standard verwenden) und ungültigem
Wert (422 zurückgeben) unterscheidet:

```php
$limit = V::queryInt(
    $params,
    'limit',
    ReminderRepository::MIN_LIMIT,   // 1
    ReminderRepository::MAX_LIMIT,   // 100
    ReminderRepository::DEFAULT_LIMIT, // 20 — zurückgegeben, wenn Schlüssel fehlt
);

if ($limit === null) {
    return $this->responseFactory->create(
        ['error' => sprintf('limit must be between %d and %d.', MIN_LIMIT, MAX_LIMIT)],
        422,
    );
}
```

`?status=` verwendet `V::enum()`, um gegen das backed enum zu validieren:

```php
$status = V::enum($rawStatus, ReminderStatus::class);

if ($status === null) {
    return $this->responseFactory->create(
        ['error' => 'status must be one of: pending, triggered, cancelled.'],
        422,
    );
}
```

`V::enum()` ruft intern `BackedEnum::tryFrom()` auf und gibt `null` für unbekannte
Werte zurück.

---

## Schema

```sql
CREATE TABLE reminders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    message    TEXT    NOT NULL,
    remind_at  TEXT    NOT NULL,  -- ISO 8601 mit Zeitzonenoffset, unverändert gespeichert
    status     TEXT    NOT NULL DEFAULT 'pending',
    created_at TEXT    NOT NULL,
    CHECK (status IN ('pending', 'triggered', 'cancelled'))
);

CREATE INDEX idx_reminders_user   ON reminders (user_id, id);
CREATE INDEX idx_reminders_status ON reminders (status, id);
```

`remind_at` wird als ursprünglicher ISO 8601-String mit dem Zeitzonenoffset des Einreichers
gespeichert (z. B. `2026-06-01T09:00:00+09:00`). Die DB normalisiert nicht auf UTC — die
Anwendung ist für den korrekten Vergleich verantwortlich (siehe `V::futureDatetime()`).

Zwei Indizes:
- `(user_id, id)` — deckt Per-Benutzer-Liste und Stornierungssuchen ab
- `(status, id)` — deckt eine Poller-Abfrage ab, die `pending`-Erinnerungen zum Auslösen abruft

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

Nur `pending`-Erinnerungen können storniert werden (`409` sonst). `triggered` wird von
einem Hintergrundjob gesetzt, wenn die Erinnerung ausgelöst wird — diese API enthält nicht
den Trigger-Endpunkt, der auf einer geplanten Aufgabe außerhalb des HTTP-Servers laufen würde.

---

## Verwandte Anleitungen

- [`iso-datetime-validation.md`](iso-datetime-validation.md) — ISO 8601-Datetime-Validierungsmuster
- [`content-scheduling.md`](content-scheduling.md) — Geplante Veröffentlichung mit zukünftigem `publish_at`
- [`approval-workflow.md`](approval-workflow.md) — 404/409-Unterscheidung bei Statusübergängen
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR-Präventionsmuster
