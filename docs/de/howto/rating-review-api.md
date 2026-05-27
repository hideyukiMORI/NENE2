# How-to: Bewertungs- und Rezensions-API

> **FT-Referenz**: FT333 (`NENE2-FT/ratinglog`) — Pro-Artikel-, Pro-Benutzer-Bewertungssystem mit Score-Validierung (1–5), Upsert-Semantik, Zusammenfassung mit Verteilungsübersicht und Schwachstellenbewertung, 16 Tests / 40+ Assertions bestanden.

Dieses Handbuch zeigt, wie man ein Bewertungssystem aufbaut, bei dem Benutzer numerische Scores mit optionalen Textrezensionen einreichen, und die API live aggregierte Zusammenfassungen berechnet.

## Schema

```sql
CREATE TABLE ratings (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id    TEXT    NOT NULL,
    rater_id   TEXT    NOT NULL,
    score      INTEGER NOT NULL CHECK (score BETWEEN 1 AND 5),
    review     TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(item_id, rater_id)
);
```

`UNIQUE(item_id, rater_id)` erzwingt eine Bewertung pro Bewerter pro Artikel. `item_id` und `rater_id` sind opake Zeichenkettenidentifikatoren — keine Fremdschlüsselbeschränkung erforderlich.

## Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `PUT`  | `/items/{itemId}/ratings/{raterId}` | Bewertung erstellen oder aktualisieren (Upsert) |
| `GET`  | `/items/{itemId}/ratings` | Alle Bewertungen für Artikel auflisten |
| `GET`  | `/items/{itemId}/ratings/summary` | Aggregierte Zusammenfassung mit Verteilung |
| `GET`  | `/items/{itemId}/ratings/{raterId}` | Bewertung eines Bewerters abrufen |
| `DELETE` | `/items/{itemId}/ratings/{raterId}` | Bewertung löschen |

## Bewertung erstellen / aktualisieren (Upsert)

```php
PUT /items/product-1/ratings/alice
{"score": 5, "review": "Ausgezeichnet!"}
→ 200  {"rater_id": "alice", "score": 5, "review": "Ausgezeichnet!", ...}

// Vorhandene Bewertung aktualisieren
PUT /items/product-1/ratings/alice
{"score": 3, "review": "Ich habe meine Meinung geändert."}
→ 200  {"score": 3}
```

`PUT` mit `UNIQUE(item_id, rater_id)` wirkt als natürlicher Upsert (`INSERT OR REPLACE`). Derselbe Endpunkt verarbeitet sowohl Erstellung als auch Aktualisierung ohne separates `PATCH`.

### Validierung

```php
// Fehlender Score
PUT /items/product-1/ratings/alice  {"review": "Gut"}
→ 422

// Außerhalb des Bereichs
PUT /items/product-1/ratings/alice  {"score": 6}
→ 422

PUT /items/product-1/ratings/alice  {"score": 0}
→ 422
```

Score muss ein Integer in [1, 5] sein. `review` ist optional (Standard: `""`).

## Bewertungen auflisten

```php
GET /items/product-1/ratings
→ 200
{
  "ratings": [
    {"rater_id": "alice", "score": 5, "review": "Ausgezeichnet!"},
    {"rater_id": "bob",   "score": 3, "review": ""}
  ]
}
```

Bewertungen werden auf den Artikel beschränkt — Bewertungen von `product-2` erscheinen nie in der Liste von `product-1`.

## Zusammenfassung mit Verteilung

```php
GET /items/product-1/ratings/summary
→ 200
{
  "count": 3,
  "average": 4.0,
  "distribution": {
    "1": 0, "2": 0, "3": 1, "4": 1, "5": 1
  }
}

// Noch keine Bewertungen
GET /items/product-2/ratings/summary
→ 200  {"count": 0, "average": 0.0, "distribution": {"1":0,"2":0,"3":0,"4":0,"5":0}}
```

`distribution` gibt immer alle fünf Schlüssel zurück, auch wenn Zählungen null sind — Clients können Stern-Balken ohne Null-Prüfungen rendern.

## Einzelne Bewertung abrufen

```php
GET /items/product-1/ratings/alice
→ 200  {"score": 4, "review": "..."}

GET /items/product-1/ratings/nobody
→ 404
```

## Bewertung löschen

```php
DELETE /items/product-1/ratings/alice
→ 200  {"deleted": true}

DELETE /items/product-1/ratings/nobody
→ 404
```

Nach dem Löschen wird die Zusammenfassung bei der nächsten Anfrage sofort neu berechnet.

---

## Schwachstellenbewertung

### V-01 — Bewertungsimitation (IDOR auf raterId) ⚠️ EXPONIERT

**Risiko**: Jeder Client kann eine Bewertung mit einem beliebigen `raterId`-Pfadsegment einreichen oder löschen.
**Befund**: EXPONIERT — `raterId` in der URL wird nicht gegen einen authentifizierten Akteur validiert.

---

### V-02 — Score-Bereichsumgehung 🛡️ SICHER

**Risiko**: Angreifer sendet `score: 0` oder `score: 6`, um ungültige Daten zu erzeugen.
**Befund**: SICHER — Score wird auf `[1, 5]` validiert, bevor in die DB geschrieben wird.

---

### V-03 — Durchschnittsvergiftung via Massen-Fake-Bewertungen ⚠️ EXPONIERT

**Risiko**: Angreifer registriert Tausende von Benutzer-IDs und sendet 1-Stern-Bewertungen.
**Befund**: EXPONIERT — keine Ratenbegrenzung oder Kontoverifizierung beim Bewertungsendpunkt.

---

### V-04 — XSS via Rezensionstext ✅ SICHER

**Risiko**: Angreifer speichert `<script>alert(1)</script>` in `review`.
**Befund**: SICHER — die API gibt `application/json` zurück. JSON-Kodierung maskiert HTML-Sonderzeichen.

---

### V-05 — SQL-Injection via itemId / raterId 🛡️ SICHER

**Risiko**: Angreifer sendet `item_id = "x' OR '1'='1"`.
**Befund**: SICHER — alle Abfragen verwenden parametrisierte Anweisungen (`?`-Platzhalter).

---

### V-06 — Unbegrenzter Rezensionstext (Speichermissbrauch) ⚠️ EXPONIERT

**Risiko**: Angreifer sendet eine 100 MB Rezensionszeichenkette.
**Befund**: EXPONIERT — keine `max_length`-Prüfung für `review`.

---

### V-07 — Durchschnitt Integer-Trunkierung 🛡️ SICHER

**Risiko**: Durchschnittsberechnung könnte Präzision verlieren.
**Befund**: SICHER — `AVG()` in SQLite gibt einen Float zurück.

---

### V-08 — Fehlende Verteilungsschlüssel (Client-Absturz) 🛡️ SICHER

**Risiko**: Wenn `distribution` Schlüssel für Scores mit null Bewertungen weglässt, stürzen Clients ab.
**Befund**: SICHER — die API gibt immer alle fünf Schlüssel (`1`–`5`) zurück, mit `0` initialisiert.

---

### V-09 — Artikelübergreifendes Datenleck 🛡️ SICHER

**Risiko**: `GET /items/product-1/ratings` gibt Bewertungen von `product-2` zurück.
**Befund**: SICHER — alle Abfragen enthalten `WHERE item_id = ?`.

---

### V-10 — Gleitkomma-Score zur Umgehung der Integer-Validierung 🛡️ SICHER

**Risiko**: Angreifer sendet `score: 4.9`, um die Bereichsprüfung zu umgehen.
**Befund**: SICHER — Score wird als strikter Integer validiert.

---

### VULN-Zusammenfassung

| ID | Schwachstelle | Befund |
|----|--------------|--------|
| V-01 | Bewertungsimitation (IDOR auf raterId) | ⚠️ EXPONIERT |
| V-02 | Score-Bereichsumgehung | 🛡️ SICHER |
| V-03 | Durchschnittsvergiftung via Massen-Fake-Bewertungen | ⚠️ EXPONIERT |
| V-04 | XSS via Rezensionstext | ✅ SICHER |
| V-05 | SQL-Injection via itemId / raterId | 🛡️ SICHER |
| V-06 | Unbegrenzter Rezensionstext (Speichermissbrauch) | ⚠️ EXPONIERT |
| V-07 | Durchschnitt Integer-Trunkierung | 🛡️ SICHER |
| V-08 | Fehlende Verteilungsschlüssel | 🛡️ SICHER |
| V-09 | Artikelübergreifendes Datenleck | 🛡️ SICHER |
| V-10 | Gleitkomma-Score zur Umgehung der Integer-Validierung | 🛡️ SICHER |

**7 SICHER, 3 EXPONIERT** — Kritisch: `raterId` authentifizieren; `review`-Längenbegrenzung hinzufügen; Ratenbegrenzung gegen Massen-Fake-Bewertungen anwenden.

---

## Was man NICHT tun sollte

| Anti-Pattern | Risiko |
|---|---|
| `raterId` aus dem Pfad ohne Authentifizierung vertrauen | Jeder Client kann als beliebiger Benutzer bewerten oder löschen |
| Kein `max_length` für Rezensionstext | Speicherbombe — eine einzige Anfrage schreibt Gigabytes in die DB |
| `null` für Verteilungsschlüssel mit null Zählung zurückgeben | Client-Code, der auf `distribution[2]` zugreift, stürzt ab |
| Durchschnitt in PHP mit `array_sum` neu berechnen | Verlustbehaftete Float-Arithmetik bei großen Datensätzen; DB `AVG()` verwenden |
| Kein Per-Benutzer-Ratenlimit | Massen-Fake-Konten vergiften Produktdurchschnitte |
| `SELECT * FROM ratings` ohne `WHERE item_id` verwenden | Artikelübergreifendes Datenleck |
