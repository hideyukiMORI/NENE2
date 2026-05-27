# How-to: Ressourcenreservierungs- und Buchungs-API

> **FT-Referenz**: FT335 (`NENE2-FT/reservationlog`) — Ressourcen-Zeitfenster-Buchung mit halboffenem Intervall-Überlappungsprävention, user_id aus öffentlichen Antworten ausgeschlossen, Stornieren-IDOR-Schutz (403), Admin/Benutzer-Dual-Level-Zugriff, 30 Tests / 70+ Assertions bestanden.

Diese Anleitung zeigt, wie ein Raum/Ressourcen-Buchungssystem gebaut wird: buchbare Ressourcen erstellen (Admin), Zeitfenster buchen (Benutzer), Überlappungen atomar verhindern und Benutzerprivatsphäre in öffentlichen Antworten schützen.

## Schema

```sql
CREATE TABLE resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL REFERENCES resources(id),
    user_id     INTEGER NOT NULL,
    starts_at   TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at     TEXT    NOT NULL,
    note        TEXT,               -- optional
    created_at  TEXT    NOT NULL
);
```

Daten werden als ISO 8601 UTC-Strings gespeichert (`2026-06-01T09:00:00Z`). Lexikographischer Vergleich ist für UTC-ISO-Strings korrekt.

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---|---|---|---|
| `POST` | `/resources` | Admin | Buchbare Ressource erstellen |
| `POST` | `/resources/{id}/book` | Benutzer | Zeitfenster buchen |
| `DELETE` | `/bookings/{id}` | Benutzer (Eigentümer) | Eigene Buchung stornieren |
| `GET` | `/bookings` | Benutzer | Eigene Buchungen auflisten |
| `GET` | `/resources/{id}/bookings` | Admin | Alle Buchungen für Ressource auflisten |

## Ressource erstellen (Admin)

```php
POST /resources
X-Admin-Key: admin-secret
{"name": "Besprechungsraum 1"}
→ 201  {"resource": {"id": 1, "name": "Besprechungsraum 1", "created_at": "..."}}

// Kein Admin-Key
POST /resources  {"name": "Raum"}
→ 401

POST /resources  X-Admin-Key: admin-secret  {"name": ""}
→ 422  // Name erforderlich

POST /resources  X-Admin-Key: admin-secret  {"name": "x".repeat(201)}
→ 422  // Name zu lang (max 200 Zeichen)
```

## Zeitfenster buchen

```php
POST /resources/1/book
X-User-Id: 101
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T10:00:00Z"}
→ 201
{
  "booking": {
    "id": 1,
    "resource_id": 1,
    "starts_at": "2026-06-01T09:00:00Z",
    "ends_at": "2026-06-01T10:00:00Z",
    "note": null,
    "created_at": "..."
    // user_id wird NICHT zurückgegeben — IDOR-Prävention
  }
}

// Optionale Notiz
POST /resources/1/book  X-User-Id: 101
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T10:00:00Z", "note": "Teammeeting"}
→ 201  {"booking": {..., "note": "Teammeeting"}}
```

### Validierungsfehler

```php
// ends_at vor starts_at
{"starts_at": "2026-06-01T10:00:00Z", "ends_at": "2026-06-01T09:00:00Z"}
→ 422

// starts_at == ends_at (Null-Dauer)
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T09:00:00Z"}
→ 422

// Fehlende starts_at
{"ends_at": "2026-06-01T10:00:00Z"}
→ 422

// Fehlende X-User-Id
POST /resources/1/book  (kein Header)
→ 400

// Null oder Überlauf X-User-Id
X-User-Id: 0    → 400
X-User-Id: 9999999999999999999  → 400

// Unbekannte Ressource
POST /resources/9999/book  X-User-Id: 101  {...}
→ 404
```

## Überlappungsprävention — Halboffene Intervalle

Slots sind **halboffen**: `[starts_at, ends_at)`. Das Ende einer Buchung und der Start der nächsten sind gleich, aber nicht überlappend.

```php
// 09:00–10:00 buchen
POST /resources/1/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 201

// Überlappend — beginnt innerhalb bestehenden Slots
POST /resources/1/book  {"starts_at": "10:00", "ends_at": "11:00"}  → 201  ✅ angrenzend, erlaubt

// Überlappend — innen
POST /resources/1/book  {"starts_at": "09:30", "ends_at": "11:00"}  → 409  ❌ Überlappung

// Identischer Slot
POST /resources/1/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 409  ❌

// Nicht überlappend auf gleicher Ressource
POST /resources/1/book  {"starts_at": "14:00", "ends_at": "15:00"}  → 201  ✅

// Gleicher Slot auf ANDERER Ressource — immer erlaubt
POST /resources/2/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 201  ✅
```

### Überlappungs-SQL-Abfrage

```sql
-- Konflikt erkennen: NOT (new.ends_at <= existing.starts_at OR new.starts_at >= existing.ends_at)
SELECT COUNT(*) FROM bookings
WHERE resource_id = ?
  AND starts_at < ?   -- existing.starts_at < new.ends_at
  AND ends_at   > ?   -- existing.ends_at > new.starts_at
```

Wenn count > 0, 409 Conflict zurückgeben.

## Buchung stornieren (IDOR-Schutz)

```php
DELETE /bookings/1
X-User-Id: 101
→ 200  {"cancelled": true}

// Falscher Benutzer → 403, NICHT 404
DELETE /bookings/1
X-User-Id: 102
→ 403  // Benutzer 102 besitzt Buchung 1 nicht

// Nicht gefunden → 404
DELETE /bookings/9999
X-User-Id: 101
→ 404
```

**403 (nicht 404) bei falscher Benutzer-Stornierung zurückgeben** — 404 würde es Benutzern erlauben, die Buchungs-IDs anderer Benutzer zu erkunden. Die Buchung existiert; der Anfragende ist nicht der Eigentümer.

```php
// Nach Stornierung ist Slot frei
DELETE /bookings/1  X-User-Id: 101  → 200
POST /resources/1/book  X-User-Id: 102  {"starts_at": "09:00", "ends_at": "10:00"}  → 201
```

### ID-Validierung

```php
DELETE /bookings/0                    → 422  // Null ist ungültig
DELETE /bookings/99999999999999999999 → 422  // Überlauf
POST /resources/0/book  X-User-Id: 101 {...} → 422  // resource_id null ungültig
```

## Eigene Buchungen auflisten (Benutzer)

```php
GET /bookings
X-User-Id: 101
→ 200
{
  "total": 2,
  "data": [
    {"id": 1, "resource_id": 1, "starts_at": "...", "ends_at": "...", "note": null, "created_at": "..."}
    // user_id ist NICHT enthalten
  ]
}

// Buchungen anderer Benutzer werden nicht zurückgegeben
// Benutzer 101 sieht nur seine eigenen Buchungen auch wenn Benutzer 102 Buchungen hat
```

## Ressourcenbuchungen auflisten (Admin)

```php
GET /resources/1/bookings
X-Admin-Key: admin-secret
→ 200
{
  "total": 2,
  "data": [
    {"id": 1, "user_id": 101, "starts_at": "2026-06-01T09:00:00Z", ...},  // user_id sichtbar
    {"id": 2, "user_id": 102, "starts_at": "2026-06-01T14:00:00Z", ...}
  ]
}
// Geordnet nach starts_at ASC

GET /resources/1/bookings  (kein Admin-Key)  → 401
GET /resources/9999/bookings  X-Admin-Key: key  → 404
```

Admin erhält `user_id` in der Antwort; öffentliche Benutzerendpunkte geben niemals `user_id` zurück.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Überlappungsprüfung: geschlossene Intervalle | Angrenzende Slots (Ende von A = Start von B) werden fälschlicherweise als überlappend abgelehnt |
| `user_id` in öffentlichen Buchungsantworten zurückgeben | Gibt preis, wer jede Buchung besitzt, ermöglicht Benutzer-Enumeration |
| 404 bei falscher Benutzer-Stornierung zurückgeben | Angreifer bestätigt, dass die Buchung existiert; 403 verwenden um Eigentümerschaft-Inkongruenz anzuerkennen |
| `starts_at >= ends_at` akzeptieren | Buchungen mit Null oder negativer Dauer korrumpieren Verfügbarkeitsberechnungen |
| Kein resource_id-Scoping in Überlappungsabfrage | Buchung von Benutzer A auf Ressource 1 blockiert Ressource 2 (Falsch-Konflikt) |
| `user_id` aus Request-Body vertrauen | Angreifer macht Buchungen im Namen beliebiger Benutzer; Identität immer aus `X-User-Id`-Header lesen |
