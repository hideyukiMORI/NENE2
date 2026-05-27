# How-to: Pin / Lesezeichen mit Reihenfolge

> **FT-Referenz**: FT327 (`NENE2-FT/pinlog`) — Benutzerbezogene Artikel-Pins mit sequentiellen Positionen, Maximal-Pin-Limit, lückenlose Neuanordnung beim Löschen, Neuanordnung via PUT, Benutzerisolation, VULN-Assessment, 19 Tests / 26 Assertions PASS.

Diese Anleitung zeigt, wie eine Artikel-Pin-Funktion erstellt wird, bei der Benutzer eine geordnete Liste mit bis zu 10 Lesezeichen mit Drag-Reorder-Unterstützung führen.

## Schema

```sql
CREATE TABLE pins (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    article_id INTEGER NOT NULL REFERENCES articles(id),
    position   INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE(user_id, article_id)
);
```

## Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST`  | `/pins` | Artikel pinnen (idempotent) |
| `DELETE`| `/pins/{articleId}` | Artikel entpinnen |
| `GET`   | `/pins` | Pins des Benutzers in Reihenfolge auflisten |
| `PUT`   | `/pins/order` | Pins neu anordnen |

Alle Endpunkte erfordern `X-User-Id`-Header. Fehlend → 401.

## Artikel Pinnen

```php
POST /pins  X-User-Id: 1
{"article_id": 3}
→ 201  {"article_id": 3, "position": 1}

POST /pins  X-User-Id: 1  {"article_id": 7}
→ 201  {"article_id": 7, "position": 2}

// Idempotent — denselben Artikel zweimal pinnen
POST /pins  X-User-Id: 1  {"article_id": 3}
→ 200  (bereits gepinnt, keine Änderung)
```

### Limit

```php
// Bereits 10 Pins
POST /pins  X-User-Id: 1  {"article_id": 11}
→ 422  {"max": 10}
```

### Fehlerfälle

```php
// Keine Auth
POST /pins  {"article_id": 1}        → 401
// Fehlende article_id
POST /pins  X-User-Id: 1  {}         → 422
// Nicht existierender Artikel
POST /pins  X-User-Id: 1  {"article_id": 999} → 404
```

## Entpinnen

```php
DELETE /pins/3  X-User-Id: 1  → 204
DELETE /pins/3  X-User-Id: 1  → 404  // bereits entfernt
```

### Positionsverdichtung nach Löschen

Beim Löschen eines Pins werden die Positionen lückenlos neu verdichtet:

```
Vorher: [1→Art1, 2→Art2, 3→Art3]
DELETE /pins/2
Nachher:  [1→Art1, 2→Art3]   // Position 2 ist jetzt Art3
```

```php
// Nach dem Entpinnen ist die Lücke geschlossen
GET /pins  X-User-Id: 1
→ {"pins": [
     {"article_id": 1, "position": 1},
     {"article_id": 3, "position": 2}   // Position 3 → 2
  ], "count": 2}
```

## Pins auflisten

```php
GET /pins  X-User-Id: 1
→ 200
{
  "pins": [
    {"article_id": 3, "position": 1},
    {"article_id": 1, "position": 2},
    {"article_id": 2, "position": 3}
  ],
  "count": 3
}

// Leer
GET /pins  X-User-Id: 99
→ {"pins": [], "count": 0}
```

Ergebnisse sind nach `position ASC` geordnet. Benutzer 2 sieht niemals Benutzer 1s Pins.

## Neuanordnen

```php
PUT /pins/order  X-User-Id: 1
{"article_ids": [3, 1, 2]}
→ 200
{
  "pins": [
    {"article_id": 3, "position": 1},
    {"article_id": 1, "position": 2},
    {"article_id": 2, "position": 3}
  ]
}

// Unbekannte article_id (nicht gepinnt)
{"article_ids": [1, 99]}  → 422

// Kein X-User-Id
PUT /pins/order  {"article_ids": [1]}  → 401
// Fehlender Body
PUT /pins/order  X-User-Id: 1  {}     → 422
```

---

## Schwachstellenbewertung

### V-01 — IDOR beim Entpinnen ✅ SAFE

**Risiko**: Benutzer 2 entpinnt Artikel von Benutzer 1 durch Erraten von Artikel-IDs.
**Befund**: SAFE — DELETE-Abfrage enthält `WHERE user_id = $authUserId AND article_id = $articleId`. Benutzerübergreifendes Löschen findet 0 Zeilen → 404.

### V-02 — IDOR beim Neuanordnen ✅ SAFE

**Risiko**: Benutzer 2 ordnet die Pin-Liste von Benutzer 1 neu.
**Befund**: SAFE — Neuanordnung validiert, dass alle `article_ids` in der Pin-Liste des authentifizierten Benutzers sind. Fremde IDs geben 422 zurück.

### V-03 — Pin-Limit-Bypass ✅ SAFE

**Risiko**: Angreifer sendet gleichzeitige Pin-Anfragen, um das 10-Pin-Limit zu überschreiten.
**Befund**: SAFE — `UNIQUE(user_id, article_id)` verhindert Duplikate. Pin-Anzahl wird vor dem Insert geprüft. Gleichzeitige Inserts laufen auf den Unique-Constraint.

### V-04 — Nicht existierenden Artikel pinnen ✅ SAFE

**Risiko**: Angreifer pinnt `article_id=999999`, um eine hängende FK-Referenz einzufügen.
**Befund**: SAFE — Existenzprüfung vor dem Insert. Nicht existierender Artikel gibt 404 zurück.

### V-05 — Artikel anderer Benutzer pinnen ✅ SAFE

**Risiko**: Benutzerübergreifender Pin (Benutzer 2 pinnt als Benutzer 1 durch Manipulation von `X-User-Id`).
**Befund**: SAFE — `X-User-Id` ist das Authentifizierungs-Token in diesem FT. In der Produktion ein signiertes JWT/Session verwenden — niemals einem clientseitig bereitgestellten Benutzer-ID-Header direkt vertrauen.

### V-06 — Positions-Lücke nach Löschen enthüllt Reihenfolge ✅ SAFE

**Risiko**: Lücken in Positionen (`1, 3`) verraten, dass ein Löschen stattgefunden hat; Angreifer schließt auf Löschhistorie.
**Befund**: SAFE — Positionen werden sofort beim Löschen verdichtet. Externe Beobachter können die Löschreihenfolge nicht erkennen.

### VULN-Zusammenfassung

| ID | Schwachstelle | Befund |
|----|---------------|---------|
| V-01 | IDOR beim Entpinnen | ✅ SAFE |
| V-02 | IDOR beim Neuanordnen | ✅ SAFE |
| V-03 | Pin-Limit-Bypass | ✅ SAFE |
| V-04 | Nicht existierenden Artikel pinnen | ✅ SAFE |
| V-05 | Benutzerübergreifendes Pinnen | ✅ SAFE |
| V-06 | Lücke enthüllt Löschhistorie | ✅ SAFE |

**6 SAFE, 0 EXPOSED** — Keine kritischen Befunde.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Kein Maximal-Pin-Limit | Unbegrenzte Liste verschlechtert Abfrageleistung und UX |
| Positions-Lücken nach Löschen lassen | Client-Sortierung nach Position bricht zusammen; erfordert clientseitige Umnumerierung |
| Artikel-Existenzprüfung beim Pinnen überspringen | Hängende Referenzen verwirren Clients beim Rendern von Pin-Listen |
| `X-User-Id`-Header in Produktion vertrauen | Jeder Client kann ihn setzen; signierte Authentifizierung verwenden (JWT, Session) |
| Kein `UNIQUE(user_id, article_id)` | Duplikat-Pins blähen Anzahl auf und verwirren Neuanordnungslogik |
