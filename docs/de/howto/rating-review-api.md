# Anleitung: Bewertungs- und Rezensions-API

> **FT-Referenz**: FT333 (`NENE2-FT/ratinglog`) — Pro-Artikel-, Pro-Benutzer-Bewertungssystem mit Score-Validierung (1–5), Upsert-Semantik, Zusammenfassung mit Verteilungsaufschlüsselung und Sicherheitsbewertung, 16 Tests / 40+ Assertions BESTANDEN.

Diese Anleitung zeigt, wie ein Bewertungssystem erstellt wird, bei dem Benutzer numerische Scores mit optionalen Textrezensionen einreichen, und die API Live-Aggregatzusammenfassungen berechnet.

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

`UNIQUE(item_id, rater_id)` erzwingt eine Bewertung pro Bewerter pro Artikel. `item_id` und `rater_id` sind opake String-Identifikatoren — keine Fremdschlüssel-Einschränkung erforderlich.

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `PUT`   | `/items/{itemId}/ratings/{raterId}` | Bewertung erstellen oder aktualisieren (Upsert) |
| `GET`   | `/items/{itemId}/ratings` | Alle Bewertungen für einen Artikel auflisten |
| `GET`   | `/items/{itemId}/ratings/summary` | Aggregatzusammenfassung mit Verteilung |
| `GET`   | `/items/{itemId}/ratings/{raterId}` | Bewertung eines bestimmten Bewerters abrufen |
| `DELETE`| `/items/{itemId}/ratings/{raterId}` | Bewertung löschen |

## Bewertung erstellen / aktualisieren (Upsert)

```php
PUT /items/product-1/ratings/alice
{"score": 5, "review": "Excellent!"}
→ 200  {"rater_id": "alice", "score": 5, "review": "Excellent!", ...}

// Vorhandene Bewertung aktualisieren
PUT /items/product-1/ratings/alice
{"score": 3, "review": "Changed my mind."}
→ 200  {"score": 3}
```

`PUT` mit `UNIQUE(item_id, rater_id)` wirkt als natürliches Upsert (`INSERT OR REPLACE`). Derselbe Endpunkt verarbeitet Erstellen und Aktualisieren ohne separates `PATCH`.

### Validierung

```php
// Fehlender Score
PUT /items/product-1/ratings/alice  {"review": "Nice"}
→ 422

// Außerhalb des Bereichs
PUT /items/product-1/ratings/alice  {"score": 6}
→ 422

PUT /items/product-1/ratings/alice  {"score": 0}
→ 422
```

Score muss ein Integer im Bereich [1, 5] sein. `review` ist optional (Standard: `""`).

## Bewertungen auflisten

```php
GET /items/product-1/ratings
→ 200
{
  "ratings": [
    {"rater_id": "alice", "score": 5, "review": "Excellent!"},
    {"rater_id": "bob",   "score": 3, "review": ""}
  ]
}
```

Bewertungen sind auf den Artikel beschränkt — Bewertungen von `product-2` erscheinen nie in der Liste von `product-1`.

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

`distribution` gibt immer alle fünf Schlüssel zurück, auch wenn die Anzahl null ist — Clients können Sterne-Balken ohne Null-Prüfungen rendern.

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

```php
// Vorher: alice(5) + bob(1), average=3.0
DELETE /items/product-1/ratings/bob

// Nachher: nur alice(5)
GET /items/product-1/ratings/summary
→ 200  {"count": 1, "average": 5.0}
```

---

## Sicherheitsbewertung

### V-01 — Bewertungsverkörperung (IDOR bei raterId) ⚠️ EXPONIERT

**Risiko**: Jeder Client kann eine Bewertung mit einem beliebigen `raterId`-Pfadsegment einreichen oder löschen.
**Befund**: EXPONIERT — `raterId` in der URL wird nicht gegen einen authentifizierten Akteur validiert. Ein Angreifer kann eine 1-Stern-Bewertung als `raterId: "competitor"` posten oder die Bewertung eines anderen Benutzers löschen. Minderung: Bewerter authentifizieren (Session, JWT oder `X-User-Id`-Header) und Anfragen ablehnen, bei denen die authentifizierte Identität nicht mit dem Pfad-`raterId` übereinstimmt.

---

### V-02 — Score-Bereichs-Bypass 🛡️ SICHER

**Risiko**: Angreifer sendet `score: 0` oder `score: 6`, um ungültige Daten zu erzeugen oder Durchschnittswerte zu verfälschen.
**Befund**: SICHER — Score wird vor jedem DB-Schreibvorgang auf `[1, 5]` validiert. Außerhalb des Bereichs liegende Werte geben 422 zurück. Der DB-seitige `CHECK (score BETWEEN 1 AND 5)` bietet eine sekundäre Sicherung.

---

### V-03 — Durchschnittsvergiftung durch Massen-Fake-Bewertungen ⚠️ EXPONIERT

**Risiko**: Angreifer registriert Tausende von Benutzer-IDs und gibt 1-Stern-Bewertungen ab, um den Durchschnittswert eines Produkts zu senken.
**Befund**: EXPONIERT — Keine Ratenbegrenzung oder Kontoüberprüfung wird am Bewertungsendpunkt erzwungen. Minderung: Kontoalter / E-Mail-Verifizierung vor der Bewertung erfordern; Per-IP- und Per-Benutzer-Ratenlimits anwenden; statistische Anomalien erkennen (plötzlicher Ansturm niedriger Scores).

---

### V-04 — XSS über Rezensionstext ✅ SICHER

**Risiko**: Angreifer speichert `<script>alert(1)</script>` in `review`, um JavaScript auf Clients auszuführen, die die Rezension als HTML rendern.
**Befund**: SICHER — Die API gibt `application/json` zurück. JSON-Kodierung maskiert HTML-Sonderzeichen (`<`, `>`, `&`). Solange Clients den JSON-Wert als Text analysieren und rendern (nicht als `innerHTML`), wird gespeichertes XSS verhindert. Serverseitige HTML-Kodierung als zusätzliche Schicht wird empfohlen.

---

### V-05 — SQL-Injection über itemId / raterId 🛡️ SICHER

**Risiko**: Angreifer sendet `item_id = "x' OR '1'='1"` oder `rater_id = "'; DROP TABLE ratings--"`, um die Abfrage zu manipulieren.
**Befund**: SICHER — Alle Abfragen verwenden parametrisierte Anweisungen (`?`-Platzhalter). Pfadsegmente werden als Bindewerte übergeben, nie in SQL-Strings interpoliert.

---

### V-06 — Unbegrenzter Rezensionstext (Speichermissbrauch) ⚠️ EXPONIERT

**Risiko**: Angreifer sendet einen 100 MB großen Rezensionsstring, um Datenbank-/Speicherressourcen zu erschöpfen.
**Befund**: EXPONIERT — Keine `max_length`-Prüfung wird für `review` erzwungen. Minderung: Eine `MAX_REVIEW_LENGTH`-Konstante hinzufügen (z. B. 2000 Zeichen) und 422 zurückgeben, wenn überschritten. Anfragengrößen-Middleware bietet eine sekundäre Sicherung.

---

### V-07 — Durchschnitts-Integer-Abschneidung bei Zusammenfassung 🛡️ SICHER

**Risiko**: Das Durchschnittlich von 3 Bewertungen (5+3+4=12, 12/3=4.0) könnte auf manchen DB-Engines Präzision verlieren.
**Befund**: SICHER — `AVG()` in SQLite gibt einen Float zurück. PHP wandelt das Ergebnis vor der Kodierung in `float` um. `(int)(5+3)/2`-Stil-Abschneidung wird nicht verwendet.

---

### V-08 — Fehlende Schlüssel in Verteilung (Client-Absturz) 🛡️ SICHER

**Risiko**: Wenn `distribution` Schlüssel für Scores mit null Bewertungen weglässt, stürzen Clients ab, die auf `distribution[1]` zugreifen, mit `undefined`.
**Befund**: SICHER — Die API gibt immer alle fünf Schlüssel (`1`–`5`) mit `0` initialisiert zurück. Clients benötigen keine defensiven Null-Prüfungen.

---

### V-09 — Artikelübergreifendes Datenleck 🛡️ SICHER

**Risiko**: `GET /items/product-1/ratings` gibt Bewertungen von `product-2` zurück.
**Befund**: SICHER — Alle Abfragen enthalten `WHERE item_id = ?`. Der Isolationstest verifiziert explizit, dass die Bewertung von `product-2` nicht in der Liste von `product-1` erscheint.

---

### V-10 — Float-Score, um Integer-Validierung zu umgehen 🛡️ SICHER

**Risiko**: Angreifer sendet `score: 4.9` (rundet auf 5) oder `score: 5.1` (rundet auf 5 oder 6), um die Bereichsprüfung zu umgehen.
**Befund**: SICHER — Score wird als strikter Integer validiert. Ein JSON-Float besteht die Typvalidierung nicht und gibt 422 zurück, bevor eine Bereichsprüfung stattfindet.

---

### VULN-Zusammenfassung

| ID | Schwachstelle | Befund |
|----|---------------|--------|
| V-01 | Bewertungsverkörperung (IDOR bei raterId) | ⚠️ EXPONIERT |
| V-02 | Score-Bereichs-Bypass | 🛡️ SICHER |
| V-03 | Durchschnittsvergiftung durch Massen-Fake-Bewertungen | ⚠️ EXPONIERT |
| V-04 | XSS über Rezensionstext | ✅ SICHER |
| V-05 | SQL-Injection über itemId / raterId | 🛡️ SICHER |
| V-06 | Unbegrenzter Rezensionstext (Speichermissbrauch) | ⚠️ EXPONIERT |
| V-07 | Durchschnitts-Integer-Abschneidung bei Zusammenfassung | 🛡️ SICHER |
| V-08 | Fehlende Schlüssel in Verteilung | 🛡️ SICHER |
| V-09 | Artikelübergreifendes Datenleck | 🛡️ SICHER |
| V-10 | Float-Score umgeht Integer-Validierung | 🛡️ SICHER |

**7 SICHER, 3 EXPONIERT** — Kritisch: `raterId` authentifizieren; `review`-Längenbegrenzung hinzufügen; Ratenbegrenzung gegen Massen-Fake-Bewertungen anwenden.

---

## Was man nicht tun sollte

| Anti-Muster | Risiko |
|---|---|
| `raterId` aus dem Pfad ohne Authentifizierung vertrauen | Jeder Client kann als beliebiger Benutzer bewerten oder löschen |
| Keine `max_length` für Rezensionstext | Speicher-Bombe — eine einzelne Anfrage schreibt Gigabytes in die DB |
| `null` für Verteilungsschlüssel mit null Zähler zurückgeben | Client-Code, der auf `distribution[2]` zugreift, stürzt ab |
| Durchschnitt in PHP mit `array_sum` neu berechnen | Verlustbehaftete Float-Arithmetik bei großen Datensätzen; DB `AVG()` verwenden |
| Kein Per-Benutzer-Ratenlimit | Massen-Fake-Konten vergiften Produktdurchschnitte |
| `SELECT * FROM ratings` ohne `WHERE item_id` verwenden | Artikelübergreifendes Datenleck |
