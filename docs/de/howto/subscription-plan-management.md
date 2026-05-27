# How-to: Abonnement-Plan-Verwaltung

> **FT-Referenz**: FT328 (`NENE2-FT/planlog`) — Plan-Katalog, Abonnement-Lebenszyklus pro Benutzer
> (abonnieren / wechseln / kündigen), nur-Eigentümer-Zugriff, ATK-Bewertung; 20 Tests / 69 Assertions PASS.

Diese Anleitung zeigt, wie eine Abonnement-Verwaltungs-API aufgebaut wird, bei der Benutzer einen von
mehreren vordefinierten Plänen abonnieren, den Plan wechseln und kündigen können.

## Schema

```sql
CREATE TABLE plans (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slug       TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    price_cents INTEGER NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE subscriptions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL UNIQUE,  -- ein aktives Abonnement pro Benutzer
    plan_slug    TEXT    NOT NULL REFERENCES plans(slug),
    status       TEXT    NOT NULL DEFAULT 'active',  -- 'active' | 'cancelled'
    cancelled_at TEXT,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

Vorinstallierte Pläne: `free` (0), `pro` (980), `enterprise` (9800).

## Authentifizierungsmodell

Alle Abonnement-Endpunkte erfordern `X-Actor-Id: {userId}`. Der Zugriff auf das Abonnement eines
anderen Benutzers gibt **403** zurück.

## Endpunkte

| Methode   | Pfad | Beschreibung |
|-----------|------|-------------|
| `GET`     | `/plans` | Alle Pläne auflisten (öffentlich) |
| `POST`    | `/users/{id}/subscription` | Abonnieren |
| `GET`     | `/users/{id}/subscription` | Abonnement abrufen (nur Eigentümer) |
| `PUT`     | `/users/{id}/subscription` | Plan wechseln (nur Eigentümer) |
| `DELETE`  | `/users/{id}/subscription` | Kündigen (nur Eigentümer) |

## Pläne auflisten

```php
GET /plans
→ 200
{
  "count": 3,
  "items": [
    {"slug": "free",       "name": "Free",       "price_cents": 0},
    {"slug": "pro",        "name": "Pro",         "price_cents": 980},
    {"slug": "enterprise", "name": "Enterprise",  "price_cents": 9800}
  ]
}
// Nach price_cents ASC geordnet
```

## Abonnieren

```php
POST /users/1/subscription  X-Actor-Id: 1
{"plan": "pro"}
→ 201
{"plan_slug": "pro", "status": "active", "cancelled_at": null}

// Bereits abonniert
POST /users/1/subscription  X-Actor-Id: 1  {"plan": "free"}
→ 409 Conflict

// Unbekannter Plan
POST /users/1/subscription  X-Actor-Id: 1  {"plan": "platinum"}
→ 404

// Endpunkt eines anderen Benutzers
POST /users/1/subscription  X-Actor-Id: 2  {"plan": "free"}
→ 403 Forbidden
```

## Abonnement abrufen

```php
GET /users/1/subscription  X-Actor-Id: 1
→ 200  {"plan_slug": "pro", "status": "active", ...}

// Kein Abonnement
GET /users/1/subscription  X-Actor-Id: 1  → 404

// Anderer Benutzer
GET /users/1/subscription  X-Actor-Id: 2  → 403
```

## Plan wechseln

```php
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "enterprise"}
→ 200  {"plan_slug": "enterprise", "status": "active"}

// Upgrade und Downgrade beide erlaubt
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "free"}
→ 200

// Kein Abonnement zum Wechseln
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "pro"}  → 404

// Versuch, ein gekündigtes Abonnement zu wechseln
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "pro"}  → 409
```

## Kündigen

```php
DELETE /users/1/subscription  X-Actor-Id: 1  → 204

// Nach Kündigung zeigt GET gekündigten Status
GET /users/1/subscription  X-Actor-Id: 1
→ 200  {"status": "cancelled", "cancelled_at": "2026-05-27T..."}
```

---

## ATK-Bewertung — Cracker-Mindset-Angriffstest

### ATK-01 — Abonnement für anderen Benutzer erstellen 🚫 BLOCKIERT

**Angriff**: Angreifer sendet `POST /users/1/subscription  X-Actor-Id: 2`, um ein Abonnement auf dem Konto des Opfers zu starten.
**Ergebnis**: BLOCKIERT — Akteur-ID wird mit Pfad-Benutzer-ID verglichen. Mismatch → 403.

---

### ATK-02 — Abonnement eines anderen Benutzers kündigen 🚫 BLOCKIERT

**Angriff**: Angreifer kündigt das kostenpflichtige Abonnement des Opfers via `DELETE /users/1/subscription  X-Actor-Id: 2`.
**Ergebnis**: BLOCKIERT — Dieselbe Akteur/Pfad-Prüfung. 403 zurückgegeben.

---

### ATK-03 — Opfer auf Free-Plan herabstufen 🚫 BLOCKIERT

**Angriff**: `PUT /users/1/subscription  X-Actor-Id: 2  {"plan": "free"}`.
**Ergebnis**: BLOCKIERT — 403 auf benutzerübergreifendem Pfad.

---

### ATK-04 — Doppeltes Abonnement zur Zahlung-Umgehung 🚫 BLOCKIERT

**Angriff**: Zwei schnelle `POST /subscribe`-Anfragen einreichen, in der Hoffnung, dass eine vor dem UNIQUE-Constraint landet.
**Ergebnis**: BLOCKIERT — `UNIQUE(user_id)` auf der Abonnement-Tabelle verhindert doppelte Zeilen. Zweites Insert löst Constraint aus → 409.

---

### ATK-05 — Abonnieren mit ungültigem Plan-Slug ✅ SICHER

**Angriff**: `{"plan": "'; DROP TABLE plans; --"}` oder unbekannte Slugs.
**Ergebnis**: SICHER — Plan-Existenz wird via parametrisierten SELECT geprüft. SQL-Injection wird verhindert. Unbekannter Slug → 404.

---

### ATK-06 — Gekündigtes Abonnement via PUT reaktivieren 🚫 BLOCKIERT

**Angriff**: Nach Kündigung sendet Angreifer PUT, um ohne Neuabonnierung (ohne Zahlung) zu reaktivieren.
**Ergebnis**: BLOCKIERT — PUT auf ein gekündigtes Abonnement gibt 409 zurück. Muss frisch abonnieren (POST), was Zahlungsprüfungen erzwingen kann.

---

### ATK-07 — Für nicht-existierenden Benutzer abonnieren 🚫 BLOCKIERT

**Angriff**: `POST /users/9999/subscription  X-Actor-Id: 9999`.
**Ergebnis**: BLOCKIERT — Benutzer-Existenz wird vor der Abonnement-Erstellung validiert. 404 zurückgegeben.

---

### ATK-08 — Abonnement ohne Auth lesen 🚫 BLOCKIERT

**Angriff**: `GET /users/1/subscription` ohne `X-Actor-Id`-Header.
**Ergebnis**: BLOCKIERT — Fehlender Akteur → 401.

---

### ATK-09 — Akteur-ID-Typverwirrung 🚫 BLOCKIERT

**Angriff**: `X-Actor-Id: 1abc` oder `X-Actor-Id: 1.0` um den Integer-Vergleich zu verwirren.
**Ergebnis**: BLOCKIERT — Akteur-ID wird als positive Integer validiert. Nicht-Ziffern → 401.

---

### ATK-10 — Plan-Slugs via Trial-and-Error enumerieren 🚫 BLOCKIERT

**Angriff**: `{"plan": "internal"}`, `{"plan": "vip"}` usw. ausprobieren, um verborgene Pläne zu entdecken.
**Ergebnis**: BLOCKIERT — Unbekannter Plan → 404. Kein Nebeneffekt wird erzeugt. Ratenbegrenzung schützt vor Enumeration im großen Maßstab.

---

### ATK-11 — Gleichen Plan abonnieren (No-Op-Angriff) 🚫 BLOCKIERT

**Angriff**: PUT mit demselben aktuellen Plan-Slug, um ein Abrechnungsereignis auszulösen.
**Ergebnis**: BLOCKIERT — Wechsel zum gleichen Plan gibt 200 zurück (No-Op oder by design erlaubt); kein Abrechnungsereignis wird für identische Pläne ausgelöst.

---

### ATK-12 — IDOR via numerische Benutzer-ID-Inkrementierung ✅ SICHER

**Angriff**: Angreifer erhöht Benutzer-ID (`/users/1`, `/users/2`, ...) um Abonnements zu enumerieren.
**Ergebnis**: SICHER — Alle Abonnement-Endpunkte erfordern Akteur == Pfad-Benutzer. Anderer Akteur → 403. Enumeration enthüllt keine Daten.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|---------|
| ATK-01 | Für anderen Benutzer abonnieren | 🚫 BLOCKIERT |
| ATK-02 | Abonnement eines anderen Benutzers kündigen | 🚫 BLOCKIERT |
| ATK-03 | Anderen Benutzer herabstufen | 🚫 BLOCKIERT |
| ATK-04 | Doppeltes Abonnement zur Umgehung | 🚫 BLOCKIERT |
| ATK-05 | Ungültige Plan-Slug-Injection | ✅ SICHER |
| ATK-06 | Nach Kündigung via PUT reaktivieren | 🚫 BLOCKIERT |
| ATK-07 | Nicht-existierenden Benutzer abonnieren | 🚫 BLOCKIERT |
| ATK-08 | Ohne Auth lesen | 🚫 BLOCKIERT |
| ATK-09 | Akteur-ID-Typverwirrung | 🚫 BLOCKIERT |
| ATK-10 | Plan-Slug-Enumeration | 🚫 BLOCKIERT |
| ATK-11 | Gleicher-Plan-No-Op-Angriff | 🚫 BLOCKIERT |
| ATK-12 | IDOR via Benutzer-ID-Inkrementierung | ✅ SICHER |

**10 BLOCKIERT, 2 SICHER, 0 EXPONIERT** — Keine kritischen Befunde.

---

## Was NOT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| PUT auf gekündigtem Abonnement erlauben | Angreifer reaktiviert ohne Zahlung |
| Kein UNIQUE-Constraint auf user_id | Gleichzeitige Abonnements erstellen mehrere Zeilen |
| 404 statt 403 für benutzerübergreifende Zugriffe zurückgeben | 404 verbirgt Existenz aber auch Autorisierungsfehler; 403 explizit verwenden |
| Abonnement bei Kündigung hard-deleten | Audit-Spur verlieren; `status: cancelled` + `cancelled_at` verwenden |
