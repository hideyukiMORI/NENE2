# Anleitung: Ressourcenbuchungssystem

## Überblick

Diese Anleitung behandelt den Aufbau einer Ressourcenbuchungs-API mit NENE2. Zu den Funktionen gehören Kapazitätsdurchsetzung, Doppelbuchungsverhinderung, Per-Benutzer-IDOR-Isolation und Admin-Stornierung.

**Referenzimplementierung**: `../NENE2-FT/bookinglog/`

---

## Schema-Design

```sql
CREATE TABLE IF NOT EXISTS resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE,
    capacity   INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    slot_date   TEXT    NOT NULL,   -- 'YYYY-MM-DD'
    slot_hour   INTEGER NOT NULL,   -- 0-23
    created_at  TEXT    NOT NULL,
    cancelled   INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
    UNIQUE (resource_id, user_id, slot_date, slot_hour)
);
```

Wichtige Einschränkungen:
- `UNIQUE (resource_id, user_id, slot_date, slot_hour)` — eine Buchung pro Benutzer pro Slot.
- `cancelled`-Soft-Delete-Flag — Geschichte beibehalten und gleichzeitig Neubuchungen erlauben.
- Kapazität wird zur Abfragezeit geprüft (aktive Buchungen zählen vs. resource.capacity).

---

## Routentabelle

| Methode | Pfad | Authentifizierung | Beschreibung |
|---------|------|-------------------|-------------|
| `GET` | `/resources` | Keine | Alle Ressourcen auflisten |
| `POST` | `/resources` | Admin | Ressource erstellen |
| `POST` | `/bookings` | Benutzer | Slot buchen |
| `GET` | `/bookings` | Benutzer | Eigene Buchungen auflisten |
| `GET` | `/bookings/{id}` | Benutzer | Eine Buchung abrufen |
| `DELETE` | `/bookings/{id}` | Benutzer/Admin | Buchung stornieren |

---

## Doppelbuchungsverhinderung

Zuerst prüfen, ob der Benutzer diesen Slot bereits hat (Anwendungsebene):

```php
$stmt = $this->pdo->prepare(
    'SELECT id FROM bookings WHERE resource_id = :rid AND user_id = :uid
     AND slot_date = :d AND slot_hour = :h AND cancelled = 0'
);
$stmt->execute([...]);
if ($stmt->fetch() !== false) {
    return 'double_booking';
}
```

Dann Kapazität prüfen:

```php
$count = $this->countSlotBookings($resourceId, $date, $hour);
if ($count >= (int) $resource['capacity']) {
    return 'capacity_full';
}
```

---

## IDOR-Isolation

Benutzer können nur ihre eigenen Buchungen lesen/stornieren. 404 zurückgeben (nicht 403), um die Existenz nicht preiszugeben:

```php
if (!$this->isAdmin($req) && (int) $booking['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Booking not found.');
}
```

---

## Admin-Stornierung ohne X-User-Id

Admin kann jede Buchung stornieren, ohne seine eigene Benutzer-ID anzugeben:

```php
$isAdmin = $this->isAdmin($req);
$uid     = $this->uid($req);
if ($uid === null && !$isAdmin) {
    return $this->problem(400, 'bad-request', 'X-User-Id required.');
}
$result = $this->repo->cancel($id, $uid ?? 0, $isAdmin);
```

---

## Validierungsregeln

| Feld | Regel |
|------|-------|
| `resource_id` | `is_int()` + positiv |
| `slot_date` | Regex `/\A\d{4}-\d{2}-\d{2}\z/` |
| `slot_hour` | `is_int()` + 0–23 |
| `capacity` | `is_int()` + positiv |
| `name` | Nicht-leerer String |

---

## HTTP-Statuscodes

| Situation | Status |
|-----------|--------|
| Ressource erstellt | 201 |
| Buchung bestätigt | 201 |
| Buchung gefunden / Liste | 200 |
| Kein X-User-Id | 400 |
| Ungültiger Feldtyp | 422 |
| Ungültiges Datumsformat | 422 |
| slot_hour außerhalb 0–23 | 422 |
| Ressource nicht gefunden | 404 |
| Buchung nicht gefunden | 404 |
| Kein Admin-Key | 403 |
| Eigene Buchung stornieren | 200 |
| Fremde Buchung stornieren | 403 |
| Doppelbuchung | 409 |
| Kapazität voll | 409 |

---

## Abgedeckte VULN-Muster

| VULN | Muster | Abwehr |
|------|--------|--------|
| A | IDOR: Benutzer sieht fremde Buchung | `WHERE user_id = :uid` + 404 |
| B | Negative resource_id | `is_int() + > 0`-Prüfung |
| C | Null als slot_hour (Mitternacht) | 0-23-Bereich erlaubt 0 |
| D | SQL-Injection in slot_date | Regex-Validierung + parametrisierte Abfrage |
| E | String resource_id Typverwechslung | `is_int()`-strikte Prüfung |
| F | Doppelbuchung | Existenzprüfung vor INSERT |
| G | Kapazitätsüberlauf | COUNT vs. Kapazitätsprüfung |
| H | Kein X-User-Id | 400 mit Meldung |
| I | Fremde Buchung stornieren | `user_id`-Eigentümerprüfung → 403 |
| J | Liste gibt fremde Benutzerdaten preis | `WHERE user_id = :uid` |
| K | Admin storniert jede Buchung | `isAdmin`-Bypass-Eigentümerschaft |
| L | slot_hour = 24 (außerhalb des Bereichs) | `$hour > 23` → 422 |
