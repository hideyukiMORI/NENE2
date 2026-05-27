# How-to: API-Nutzungsmessung & Kontingent-Verwaltung

> **FT-Referenz**: FT321 (`NENE2-FT/meterlog`) — Tägliches Kontingent-Management pro Benutzer, maschinengeschlützte Nutzungserfassung, Aufschlüsselung pro Endpunkt, IDOR-Schutz, Garantie dass verbleibende Menge niemals negativ wird, 24 Tests / 92 Assertions PASS.

Diese Anleitung zeigt, wie Sie ein Nutzungsmesssystem aufbauen, das API-Aufrufe pro Benutzer pro Tag verfolgt und konfigurierbare Tageskontingente durchsetzt.

## Schema

```sql
CREATE TABLE quotas (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL UNIQUE,
    daily_limit INTEGER NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE usage_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    endpoint    TEXT    NOT NULL,
    day_key     TEXT    NOT NULL,   -- 'YYYY-MM-DD'
    recorded_at TEXT    NOT NULL
);

CREATE INDEX idx_usage_user_day ON usage_events(user_id, day_key);
```

## Konstanten

```php
const DEFAULT_DAILY_LIMIT = 1000;  // angewendet, wenn keine Kontingent-Zeile existiert
```

## Auth-Modell

```
POST /quotas               → X-Admin-Key   (Kontingentkonfiguration)
POST /usage                → X-Machine-Key (serverseitige Nutzungserfassung)
POST /usage/check          → X-Machine-Key (Pre-flight-Kontingentprüfung)
GET  /usage/{id}/breakdown → X-User-Id (selbst) ODER X-Admin-Key (beliebig)
```

## Kontingentverwaltung (Admin)

```php
POST /quotas  X-Admin-Key: admin-secret
{"user_id": 1, "daily_limit": 500}
→ 200  {"user_id": 1, "daily_limit": 500}

// Upsert — bestehendes Kontingent aktualisieren
POST /quotas  X-Admin-Key: admin-secret
{"user_id": 1, "daily_limit": 1000}
→ 200  {"user_id": 1, "daily_limit": 1000}

// Kein Admin-Schlüssel  → 401
// Falscher Schlüssel    → 401
// daily_limit <= 0      → 422
```

## Kontingentstatus

```php
GET /quotas/1
→ 200
{
  "user_id": 1,
  "daily_limit": 500,
  "used": 3,
  "remaining": 497,
  "allowed": true
}

// Benutzer ohne Kontingent-Zeile → DEFAULT_DAILY_LIMIT wird angewendet
GET /quotas/99
→ 200  {"user_id": 99, "daily_limit": 1000, "used": 0, "remaining": 1000, "allowed": true}
```

`remaining = max(0, daily_limit - used)` — **wird niemals negativ**.

## Nutzung erfassen

Wird serverseitig nach jeder erfolgreichen API-Anfrage aufgerufen:

```php
POST /usage  X-Machine-Key: machine-secret
{"user_id": 1, "endpoint": "GET /articles"}
→ 201
{
  "recorded": true,
  "user_id": 1,
  "endpoint": "GET /articles",
  "day_key": "2026-05-27"
}

// Kein Maschinenschlüssel → 401
// user_id <= 0             → 422
// leerer Endpunkt          → 422
```

## Pre-flight-Kontingentprüfung

```php
POST /usage/check  X-Machine-Key: machine-secret
{"user_id": 1}
→ 200  {"allowed": true,  "remaining": 5, "used": 0}  // innerhalb des Kontingents
→ 200  {"allowed": false, "remaining": 0, "used": 2}  // erschöpft
```

## Nutzungsaufschlüsselung

```php
GET /usage/1/breakdown?date=2026-05-27  X-User-Id: 1
→ 200
{
  "user_id": 1,
  "date": "2026-05-27",
  "total": 3,
  "breakdown": [
    {"endpoint": "GET /articles", "count": 2},
    {"endpoint": "POST /articles", "count": 1}
  ]
}

// IDOR blockiert
GET /usage/1/breakdown  X-User-Id: 2        → 403
// Admin kann auf jeden Benutzer zugreifen
GET /usage/1/breakdown  X-Admin-Key: admin  → 200
// Ungültiges Datum
GET /usage/1/breakdown?date=not-a-date      → 422
```

---

## Schwachstellenbewertung

### V-01 — Kontingent-Admin ohne Schlüssel ✅ SICHER

**Risiko**: Nicht authentifizierter Aufrufer setzt Kontingent auf 0 oder INT_MAX für beliebigen Benutzer.
**Befund**: SICHER — `POST /quotas` erfordert `X-Admin-Key`. Fehlender oder falscher Schlüssel gibt 401 zurück.

---

### V-02 — Groß-/Kleinschreibung/Varianten-Admin-Schlüssel-Bypass ✅ SICHER

**Risiko**: Angreifer versucht `ADMIN-SECRET`, `admin_secret`, `""` um die Schlüsselprüfung zu umgehen.
**Befund**: SICHER — Exakte `hash_equals()`-Übereinstimmung. Alle Varianten geben 401 zurück.

---

### V-03 — Nicht-positiver daily_limit ✅ SICHER

**Risiko**: `daily_limit=0` oder `-1` sperrt Benutzer dauerhaft aus.
**Befund**: SICHER — 422 für `daily_limit <= 0`.

---

### V-04 — Nutzungserfassung ohne Maschinenschlüssel ✅ SICHER

**Risiko**: Externer Aufrufer erfasst gefälschte Nutzung, um das Kontingent zu erschöpfen.
**Befund**: SICHER — `POST /usage` erfordert `X-Machine-Key`. 401 bei fehlendem/falschem Schlüssel.

---

### V-05 — SQL-Injection im Endpunkt-Feld ✅ SICHER

**Risiko**: `"'; DROP TABLE usage_events; --"` beschädigt die DB.
**Befund**: SICHER — Parametrisierte Abfragen. Injection wird als literaler String gespeichert. Tabelle überlebt.

---

### V-06 — Nicht-positive user_id in der Nutzung ✅ SICHER

**Risiko**: `user_id=0/-1` fügt Zeile für nicht existierenden Benutzer ein.
**Befund**: SICHER — 422 für `user_id <= 0`.

---

### V-07 — IDOR bei der Aufschlüsselung ✅ SICHER

**Risiko**: Benutzer liest Endpunkt-Nutzungsmuster eines anderen Benutzers.
**Befund**: SICHER — `X-User-Id` wird mit Pfad `{id}` verglichen. Nichtübereinstimmung → 403. Admin umgeht dies.

---

### V-08 — Ungültiges Datum in der Aufschlüsselung ✅ SICHER

**Risiko**: Path-Traversal oder unmögliches Datum im `date=`-Parameter verursacht Absturz oder SQL-Fehler.
**Befund**: SICHER — `/^\d{4}-\d{2}-\d{2}$/` + `checkdate()`-Validierung. Ungültig → 422.

---

### V-09 — Verbleibendes Kontingent wird negativ ✅ SICHER

**Risiko**: Negatives `remaining` wird Clients angezeigt, wenn die Nutzung das reduzierte Kontingent überschreitet.
**Befund**: SICHER — `remaining = max(0, $daily_limit - $used)`.

---

### V-10 — Leerer Endpunkt-String ✅ SICHER

**Risiko**: Leerer Endpunkt erstellt nicht verwendbare Aufschlüsselungszeilen.
**Befund**: SICHER — 422 für `endpoint === ''`.

---

### VULN-Zusammenfassung

| ID | Schwachstelle | Befund |
|----|---------------|---------|
| V-01 | Kontingent-Admin ohne Schlüssel | ✅ SICHER |
| V-02 | Schlüssel-Groß-/Kleinschreibungs-Bypass | ✅ SICHER |
| V-03 | Nicht-positiver daily_limit | ✅ SICHER |
| V-04 | Nutzung ohne Maschinenschlüssel | ✅ SICHER |
| V-05 | SQL-Injection im Endpunkt | ✅ SICHER |
| V-06 | Nicht-positive user_id | ✅ SICHER |
| V-07 | IDOR bei der Aufschlüsselung | ✅ SICHER |
| V-08 | Ungültiges Datumsformat | ✅ SICHER |
| V-09 | Negatives verbleibendes Kontingent | ✅ SICHER |
| V-10 | Leerer Endpunkt-String | ✅ SICHER |

**10 SICHER, 0 EXPONIERT** — Keine kritischen Befunde.

---

## Was Sie NICHT tun sollten

| Anti-Pattern | Risiko |
|---|---|
| `remaining` negativ werden lassen | Verwirrende negative Zahlen; Gate-Logik bricht |
| Kein Maschinenschlüssel bei der Nutzungserfassung | Beliebiger Client erhöht/verringert Kontingent eines anderen Benutzers |
| Keine IDOR-Prüfung bei der Aufschlüsselung | Endpunkt-Nutzungsmuster werden an unbefugte Benutzer weitergegeben |
| Nutzung vor Kontingentprüfung erfassen | Abgelehnte Aufrufe verbrauchen trotzdem Kontingent |
| `daily_limit=0` erlauben | Benutzer von Anfang an dauerhaft ausgesperrt |
