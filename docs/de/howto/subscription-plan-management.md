# Anleitung: Abonnementplan-Verwaltung

> **FT-Referenz**: FT328 (`NENE2-FT/planlog`) — Plan-Katalog, benutzerspezifischer Abonnement-Lebenszyklus (abonnieren / wechseln / kündigen), Nur-Eigentümer-Zugriff, ATK-Assessment, 20 Tests / 69 Assertions BESTANDEN.

Diese Anleitung zeigt, wie eine Abonnement-Verwaltungs-API gebaut wird, bei der Benutzer einen von mehreren vordefinierten Plänen abonnieren, den Plan wechseln und kündigen können.

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

Alle Abonnement-Endpunkte erfordern `X-Actor-Id: {userId}`. Das Zugreifen auf das Abonnement eines anderen Benutzers gibt **403** zurück.

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `GET`    | `/plans` | Alle Pläne auflisten (öffentlich) |
| `POST`   | `/users/{id}/subscription` | Abonnieren |
| `GET`    | `/users/{id}/subscription` | Abonnement abrufen (nur Eigentümer) |
| `PUT`    | `/users/{id}/subscription` | Plan wechseln (nur Eigentümer) |
| `DELETE` | `/users/{id}/subscription` | Kündigen (nur Eigentümer) |

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
// Geordnet nach price_cents ASC
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

// Nach der Kündigung zeigt GET den gekündigten Status
GET /users/1/subscription  X-Actor-Id: 1
→ 200  {"status": "cancelled", "cancelled_at": "2026-05-27T..."}
```

---

## ATK-Assessment — Cracker-Mindset-Angrifftest

### ATK-01 — Anderes Benutzerkonto abonnieren 🚫 BLOCKED

**Angriff**: Angreifer sendet `POST /users/1/subscription  X-Actor-Id: 2`, um ein Abonnement auf dem Konto des Opfers zu starten.
**Ergebnis**: BLOCKED — Actor-ID wird mit Pfad-Benutzer-ID verglichen. Abweichung → 403.

---

### ATK-02 — Abonnement eines anderen Benutzers kündigen 🚫 BLOCKED

**Angriff**: Angreifer kündigt das bezahlte Abonnement des Opfers per `DELETE /users/1/subscription  X-Actor-Id: 2`.
**Ergebnis**: BLOCKED — Gleiche Actor/Pfad-Prüfung. 403 zurückgegeben.

---

### ATK-03 — Opfer auf kostenlosen Plan herabstufen 🚫 BLOCKED

**Angriff**: `PUT /users/1/subscription  X-Actor-Id: 2  {"plan": "free"}`.
**Ergebnis**: BLOCKED — 403 auf benutzerübergreifendem Pfad.

---

### ATK-04 — Doppeltes Abonnement zur Umgehung der Zahlung 🚫 BLOCKED

**Angriff**: Zwei schnelle `POST /subscribe`-Anfragen senden, in der Hoffnung, dass eine vor der UNIQUE-Bedingung landet.
**Ergebnis**: BLOCKED — `UNIQUE(user_id)` in der Abonnement-Tabelle verhindert doppelte Zeilen. Zweites Insert wirft Bedingungsfehler → 409.

---

### ATK-05 — Mit ungültigem Plan-Slug abonnieren ✅ SAFE

**Angriff**: `{"plan": "'; DROP TABLE plans; --"}` oder unbekannte Slugs.
**Ergebnis**: SAFE — Plan-Existenz wird über parametrisiertes SELECT geprüft. SQL-Injection verhindert. Unbekannter Slug → 404.

---

### ATK-06 — Gekündigtes Abonnement via PUT reaktivieren 🚫 BLOCKED

**Angriff**: Nach der Kündigung sendet Angreifer PUT, um ohne erneutes Abonnieren zu reaktivieren (Zahlung überspringen).
**Ergebnis**: BLOCKED — PUT auf einem gekündigten Abonnement gibt 409 zurück. Muss erneut abonnieren (POST), was Zahlungsprüfungen durchsetzen kann.

---

### ATK-07 — Für nicht existierenden Benutzer abonnieren 🚫 BLOCKED

**Angriff**: `POST /users/9999/subscription  X-Actor-Id: 9999`.
**Ergebnis**: BLOCKED — Benutzer-Existenz wird vor der Abonnement-Erstellung geprüft. 404 zurückgegeben.

---

### ATK-08 — Abonnement ohne Authentifizierung lesen 🚫 BLOCKED

**Angriff**: `GET /users/1/subscription` ohne `X-Actor-Id`-Header.
**Ergebnis**: BLOCKED — Fehlender Actor → 401.

---

### ATK-09 — Pfad/Actor-ID-Typverwirrung 🚫 BLOCKED

**Angriff**: `X-Actor-Id: 1abc` oder `X-Actor-Id: 1.0`, um den Integer-Vergleich zu verwirren.
**Ergebnis**: BLOCKED — Actor-ID wird als positive Integer validiert. Nicht-Ziffern → 401.

---

### ATK-10 — Plan-Slugs durch Ausprobieren aufzählen 🚫 BLOCKED

**Angriff**: `{"plan": "internal"}`, `{"plan": "vip"}` usw. versuchen, um versteckte Pläne zu entdecken.
**Ergebnis**: BLOCKED — Unbekannter Plan → 404. Kein Seiteneffekt wird erzeugt. Rate Limiting schützt vor Aufzählung in großem Maßstab.

---

### ATK-11 — Gleichen Plan abonnieren (No-Op-Angriff) 🚫 BLOCKED

**Angriff**: PUT mit demselben aktuellen Plan-Slug, um ein Abrechnungsereignis auszulösen.
**Ergebnis**: BLOCKED — Wechsel zum gleichen Plan gibt 200 zurück (No-Op oder nach Design erlaubt); kein Abrechnungsereignis wird für identischen Plan ausgelöst.

---

### ATK-12 — IDOR via numerische Benutzer-ID-Inkrementierung ✅ SAFE

**Angriff**: Angreifer inkrementiert Benutzer-ID (`/users/1`, `/users/2`, ...), um Abonnements aufzuzählen.
**Ergebnis**: SAFE — Alle Abonnement-Endpunkte erfordern Actor == Pfad-Benutzer. Anderer Actor → 403. Aufzählung enthüllt keine Daten.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|----------|
| ATK-01 | Anderes Benutzerkonto abonnieren | 🚫 BLOCKED |
| ATK-02 | Abonnement eines anderen kündigen | 🚫 BLOCKED |
| ATK-03 | Anderen Benutzer herabstufen | 🚫 BLOCKED |
| ATK-04 | Doppeltes Abonnement umgehen | 🚫 BLOCKED |
| ATK-05 | Ungültige Plan-Slug-Injection | ✅ SAFE |
| ATK-06 | Nach Kündigung via PUT reaktivieren | 🚫 BLOCKED |
| ATK-07 | Nicht existierenden Benutzer abonnieren | 🚫 BLOCKED |
| ATK-08 | Ohne Authentifizierung lesen | 🚫 BLOCKED |
| ATK-09 | Actor-ID-Typverwirrung | 🚫 BLOCKED |
| ATK-10 | Plan-Slug-Aufzählung | 🚫 BLOCKED |
| ATK-11 | Gleicher-Plan-No-Op-Angriff | 🚫 BLOCKED |
| ATK-12 | IDOR via Benutzer-ID-Inkrementierung | ✅ SAFE |

**10 BLOCKED, 2 SAFE, 0 EXPOSED** — Keine kritischen Befunde.

---

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| PUT auf gekündigtem Abonnement erlauben | Angreifer reaktiviert ohne Zahlung |
| Kein UNIQUE-Constraint für user_id | Gleichzeitige Abonnements erstellen mehrere Zeilen |
| 404 statt 403 für benutzerübergreifende Zugriffe zurückgeben | 404 verbirgt Existenz, verbirgt aber auch Autorisierungsfehler; 403 explizit verwenden |
| Abonnement bei Kündigung hart löschen | Prüfpfad verlieren; `status: cancelled` + `cancelled_at` verwenden |
