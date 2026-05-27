# How-to: Ressourcenbuchungssystem

## Übersicht

Diese Anleitung behandelt den Aufbau einer Ressourcenbuchungs-API mit NENE2. Funktionen umfassen Kapazitätsdurchsetzung, Doppelbuchungsprävention, IDOR-Isolation pro Benutzer und Admin-Stornierung.

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

Schlüsselconstraints:
- `UNIQUE (resource_id, user_id, slot_date, slot_hour)` — eine Buchung pro Benutzer pro Slot.
- `cancelled` Soft-Delete-Flag — Geschichte beibehalten während Wiederbuchungen ermöglicht werden.
- Kapazität wird zur Abfragezeit geprüft (aktive Buchungen zählen vs. resource.capacity).

---

## Routentabelle

| Methode | Pfad | Auth | Beschreibung |
|---|---|---|---|
| `GET` | `/resources` | Keine | Alle Ressourcen auflisten |
| `POST` | `/resources` | Admin | Ressource erstellen |
| `POST` | `/bookings` | Benutzer | Slot buchen |
| `GET` | `/bookings` | Benutzer | Eigene Buchungen auflisten |
| `GET` | `/bookings/{id}` | Benutzer | Eine Buchung abrufen |
| `DELETE` | `/bookings/{id}` | Benutzer/Admin | Buchung stornieren |

---

## Doppelbuchungsprävention

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

Benutzer können nur ihre eigenen Buchungen lesen/stornieren. 404 (nicht 403) zurückgeben, um die Existenz nicht preiszugeben:

```php
if (!$this->isAdmin($req) && (int) $booking['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Buchung nicht gefunden.');
}
```

---

## Admin storniert ohne X-User-Id

Admin kann jede Buchung ohne eigene Benutzer-ID stornieren:

```php
$isAdmin = $this->isAdmin($req);
$uid     = $this->uid($req);
if ($uid === null && !$isAdmin) {
    return $this->problem(400, 'bad-request', 'X-User-Id erforderlich.');
}
$result = $this->repo->cancel($id, $uid ?? 0, $isAdmin);
```

---

## Validierungsregeln

| Feld | Regel |
|---|---|
| `resource_id` | `is_int()` + positiv |
| `slot_date` | Regex `/\A\d{4}-\d{2}-\d{2}\z/` |
| `slot_hour` | `is_int()` + 0–23 |
| `capacity` | `is_int()` + positiv |
| `name` | nicht-leerer String |

---

## HTTP-Statuscodes

| Situation | Status |
|---|---|
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
| Buchung eines anderen stornieren | 403 |
| Doppelbuchung | 409 |
| Kapazität voll | 409 |

---

## Behandelte VULN-Muster

| VULN | Muster | Abwehr |
|---|---|---|
| A | IDOR: Benutzer sieht andere Buchung | `WHERE user_id = :uid` + 404 |
| B | Negative resource_id | `is_int() + > 0`-Prüfung |
| C | null slot_hour (Mitternacht) | Bereich 0-23 erlaubt 0 |
| D | SQL-Injection in slot_date | Regex-Validierung + parametrisierte Abfrage |
| E | String resource_id Typ-Jonglage | `is_int()` strenge Prüfung |
| F | Doppelbuchung | Existenzprüfung vor INSERT |
| G | Kapazitätsüberlauf | COUNT vs. Kapazitätsprüfung |
| H | Kein X-User-Id | 400 mit Meldung |
| I | Buchung eines anderen Benutzers stornieren | `user_id`-Eigentümerprüfung → 403 |
| J | Liste gibt Daten anderer Benutzer preis | `WHERE user_id = :uid` |
| K | Admin storniert beliebige Buchung | `isAdmin` umgeht Eigentümerschaft |
| L | slot_hour = 24 (außerhalb des Bereichs) | `$hour > 23` → 422 |
