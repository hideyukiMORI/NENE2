# How-to: Benachrichtigungs-Posteingang-API

> **FT-Referenz**: FT271 (`NENE2-FT/notificationlog`) — Benachrichtigungs-Posteingang: typenbasiert allowlistet, IDOR-Schutz pro Benutzer (404 nicht 403), Admin-Fail-Closed-Muster, Bulk-Als-Gelesen-Markieren, is_read-Idempotenz, Paginierungs-Begrenzung mit PDO::PARAM_INT-Bindung, 31 Tests / 98 Assertions PASS.
>
> Ebenfalls validiert in FT222 (`NENE2-FT/notificationlog`) — VULN-Bewertung zum gleichen Muster.

Diese Anleitung zeigt, wie ein Benachrichtigungs-Posteingang-System mit typenbasierter Allowlist für Push-Benachrichtigungen, IDOR-Schutz pro Benutzer und Bulk-Als-Gelesen-Markieren mit NENE2 aufgebaut wird.

## Funktionen

- Nur-Admin-Benachrichtigungserstellung mit Typ-Allowlist
- IDOR-Schutz pro Benutzer: Benutzer sehen nur ihre eigenen Benachrichtigungen (404 bei unberechtigtem Zugriff)
- Einzel- und Bulk-Als-Gelesen-Markieren mit Eigentumsverifizierung
- Ungelesen-Anzahl bei jeder Auflistung zurückgegeben
- Optionaler Nur-Ungelesen-Filter und Paginierung
- Admin Fail-Closed

## Schema

```sql
CREATE TABLE IF NOT EXISTS notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_read    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    read_at    TEXT
);

CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications (user_id, id DESC);
```

Keine separate `users`-Tabelle — die API vertraut dem `X-User-Id`-Header (in der Produktion durch echte Auth ersetzen).

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|--------|------|------|-------------|
| `POST` | `/notifications` | Admin | Benachrichtigung für einen Benutzer erstellen |
| `GET` | `/users/{userId}/notifications` | Eigener / Admin | Benachrichtigungen auflisten |
| `POST` | `/notifications/{id}/read` | Eigener / Admin | Eine Benachrichtigung als gelesen markieren |
| `POST` | `/users/{userId}/notifications/read-all` | Eigener / Admin | Alle als gelesen markieren |

## Typ-Allowlist

Freitext-Typ-Strings werden abgelehnt, um Injection- und Enumeration-Angriffe zu verhindern:

```php
public const array ALLOWED_TYPES = [
    'system',
    'promotion',
    'social',
    'account',
    'security',
    'reminder',
];
```

Routen-Handler validiert vor jedem DB-Zugriff:

```php
if (!in_array($type, NotificationRepository::ALLOWED_TYPES, true)) {
    $allowed = implode(', ', NotificationRepository::ALLOWED_TYPES);
    return $this->problem(422, 'validation-failed', "type must be one of: {$allowed}.");
}
```

## IDOR-Schutz

Benutzer können nur ihre eigenen Benachrichtigungen lesen. Ein 404 (nicht 403) wird bei unberechtigtem Zugriff zurückgegeben, um Benutzer-ID-Enumeration zu verhindern:

```php
private function isSelfOrAdmin(ServerRequestInterface $req, int $ownerId): bool
{
    if ($this->isAdmin($req)) {
        return true;
    }
    $uid = $this->requestUserId($req);
    return $uid !== null && $uid === $ownerId;
}
```

Als-Gelesen-Markieren verifiziert auch die Eigentümerschaft vor der Aktion:

```php
// POST /notifications/{id}/read Handler
$notification = $this->repo->findById($id);
if ($notification === null) {
    return $this->problem(404, 'not-found', 'Notification not found.');
}
// IDOR: nur der Eigentümer oder Admin darf als gelesen markieren
if (!$this->isSelfOrAdmin($req, (int) $notification['user_id'])) {
    return $this->problem(404, 'not-found', 'Notification not found.');
}
```

## Admin Fail-Closed

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;   // fail-closed: kein Admin wenn Key nicht konfiguriert
    }
    $key = $req->getHeaderLine('X-Admin-Key');
    return $key !== '' && hash_equals($this->adminKey, $key);
}
```

## Paginierung

`limit` und `offset` werden im Repository begrenzt — niemals roh vom Client vertraut:

```php
private const int MAX_LIMIT = 100;

$limit  = max(1, min(self::MAX_LIMIT, $limit));
$offset = max(0, $offset);
```

PDO-Integer-Bindung verhindert SQL-Injection in LIMIT / OFFSET:

```php
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
```

## Als-Gelesen-Markieren-Idempotenz

```php
/** @return 'ok'|'not_found'|'already_read' */
public function markAsRead(int $id): string
{
    $notification = $this->findById($id);
    if ($notification === null) return 'not_found';
    if ((bool) $notification['is_read']) return 'already_read';

    // ... UPDATE SET is_read = 1, read_at = :now ...
    return 'ok';
}
```

Der Routen-Handler gibt 200 sowohl für `ok` als auch für `already_read` zurück — wodurch der Endpunkt mehrfach ohne Nebenwirkungen aufrufbar ist.

## Sicherheitsmuster

| Muster | Implementierung |
|---------|----------------|
| **Typ-Allowlist** | `in_array($type, ALLOWED_TYPES, true)` — strenge Übereinstimmung |
| **IDOR → 404** | 404 (nicht 403) zurückgeben, um Benutzer-/Benachrichtigungs-Existenz zu verbergen |
| **Eigentumsverifizierung** | Benachrichtigung abrufen, `user_id` prüfen bevor als gelesen markiert wird |
| **Admin Fail-Closed** | `if ($this->adminKey === '') return false;` |
| **`ctype_digit()`** | Pfadparameter-ID-Validierung — ReDoS-sicher |
| **Paginierungs-Begrenzung** | `max(1, min(100, $limit))` + `PDO::PARAM_INT`-Bindung |
| **`is_int()` + `> 0`** | Strenge user_id-Prüfung — lehnt Floats, Strings, negative Werte ab |

---

## Was man NICHT tun sollte

| Anti-Pattern | Risiko |
|---|---|
| Freiform-`type`-String akzeptieren | Unvalidierte Typen verschmutzen den Posteingang; keine Möglichkeit, nach sinnvollen Kategorien zu filtern |
| 403 bei unberechtigtem Benachrichtigungszugriff zurückgeben | Offenbart, ob die Benachrichtigung oder der Benutzer existiert — IDOR-Informationsleck |
| 404 von Als-Gelesen-Markieren vor der Eigentumsüberprüfung zurückgeben | Ein Angreifer erfährt, dass die Benachrichtigung existiert und jemandem gehört |
| Leeres `adminKey` als "Admin erlaubt" bedeuten lassen | Fail-Open; jede Anfrage wird zum Admin, wenn kein Key konfiguriert ist |
| Rohes `limit` aus dem Query-String vertrauen | Eine Anfrage mit `limit=999999` verursacht vollständigen Tabellenscan |
| String-Interpolation in LIMIT/OFFSET verwenden | `"LIMIT {$limit}"` mit unvalidierter Eingabe ermöglicht SQL-Injection |
