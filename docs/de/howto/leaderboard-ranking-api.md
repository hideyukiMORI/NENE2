# How-to: Bestenlisten-Ranking-API

> **FT-Referenz**: FT332 (`NENE2-FT/ranklog`) — Bestenliste mit persönlicher Bestleistungsverfolgung pro Benutzer, absteigendes Ranking, eigene Rang-Abfrage, Score-Löschung und ATK-Cracker-Mindset-Angriffsbewertung, 19 Tests / 50+ Assertions PASS.

Diese Anleitung zeigt, wie ein Multi-Bestenlisten-Rangsystem aufgebaut wird, das nur die persönliche Bestleistung pro Benutzer speichert, Rangpositionen zurückgibt und Self-Service-Score-Löschung ermöglicht.

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL
);

CREATE TABLE leaderboards (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE
);

CREATE TABLE scores (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    leaderboard_id INTEGER NOT NULL REFERENCES leaderboards(id),
    user_id        INTEGER NOT NULL REFERENCES users(id),
    score          INTEGER NOT NULL,
    submitted_at   TEXT    NOT NULL,
    UNIQUE(leaderboard_id, user_id)   -- ein Bestleistungs-Score pro Benutzer pro Bestenliste
);
```

`UNIQUE(leaderboard_id, user_id)` erzwingt einen Eintrag pro Benutzer — neue Einreichungen überschreiben nur, wenn der Score höher ist.

## Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST` | `/leaderboards` | Bestenliste erstellen |
| `POST` | `/leaderboards/{id}/scores` | Score einreichen |
| `GET`  | `/leaderboards/{id}/rankings` | Vollständiges Ranking abrufen (absteigend) |
| `GET`  | `/leaderboards/{id}/rankings/me` | Eigenen Rang abrufen |
| `DELETE` | `/leaderboards/{id}/scores/{userId}` | Eigenen Score löschen |

## Bestenliste erstellen

```php
POST /leaderboards
{"name": "Global"}
→ 201  {"id": 1, "name": "Global"}

POST /leaderboards  {"name": ""}
→ 422  // name erforderlich
```

## Score einreichen — Nur persönliche Bestleistung

```php
// Erste Einreichung
POST /leaderboards/1/scores
{"user_id": 1, "score": 1000}
→ 200  {"new_best": true}

// Besserer Score
POST /leaderboards/1/scores
{"user_id": 1, "score": 1200}
→ 200  {"new_best": true}

// Schlechterer Score — gespeicherter Wert wird NICHT aktualisiert
POST /leaderboards/1/scores
{"user_id": 1, "score": 800}
→ 200  {"new_best": false}
```

Nur die persönliche Bestleistung wird gespeichert. Eine niedrigere Einreichung wird anerkannt, aber verworfen.

```php
// Negative Scores sind gültig (Strafen, Golf-Punktwertung usw.)
POST /leaderboards/1/scores  {"user_id": 1, "score": -100}
→ 200  {"new_best": true}

// Fehler
POST /leaderboards/1/scores  {"user_id": 9999, "score": 100}
→ 404  // unbekannter Benutzer

POST /leaderboards/9999/scores  {"user_id": 1, "score": 100}
→ 404  // unbekannte Bestenliste

POST /leaderboards/1/scores  {"user_id": 1}
→ 422  // score-Feld fehlt
```

## Rankings abrufen

```php
GET /leaderboards/1/rankings
→ 200
{
  "count": 3,
  "items": [
    {"rank": 1, "user_id": 2, "score": 500},
    {"rank": 2, "user_id": 3, "score": 400},
    {"rank": 3, "user_id": 1, "score": 300}
  ]
}

// Auf Top N begrenzen
GET /leaderboards/1/rankings?limit=2
→ 200  {"count": 2, "items": [...]}  // nur Top 2
```

Rankings sind nach Score absteigend sortiert. `rank` ist 1-basiert.

### SQL

```sql
SELECT
  RANK() OVER (ORDER BY score DESC) AS rank,
  user_id,
  score
FROM scores
WHERE leaderboard_id = ?
ORDER BY score DESC
LIMIT ?
```

## Eigenen Rang abrufen

```php
GET /leaderboards/1/rankings/me
X-User-Id: 1

→ 200  {"rank": 2, "score": 300}

// Noch nicht auf dieser Bestenliste
GET /leaderboards/1/rankings/me
X-User-Id: 99
→ 404

// Fehlender Akteur-Header
GET /leaderboards/1/rankings/me
→ 400
```

`X-User-Id`-Header identifiziert den anfragenden Benutzer. Fehlender oder ungültiger Header → 400.

## Score löschen

```php
DELETE /leaderboards/1/scores/1
X-User-Id: 1
→ 204  (kein Body)

// Bereits gelöscht / nie eingereicht
DELETE /leaderboards/1/scores/1
X-User-Id: 1
→ 404
```

Nach der Löschung gibt `GET /rankings/me` für diesen Benutzer 404 zurück.

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Score für einen anderen Benutzer einreichen (Body-IDOR) ⚠️ EXPOSED

**Angriff**: Angreifer sendet `{"user_id": 2, "score": 999999}`, um einen anderen Benutzer an die Spitze der Bestenliste zu schieben.
**Ergebnis**: EXPOSED — Der Endpunkt verwendet `user_id` aus dem Request-Body, ohne zu verifizieren, dass der Akteur übereinstimmt. Eine Autorisierungsprüfung (`X-User-Id == body.user_id`) verhindert dies. Für Wettbewerbs-Bestenlisten `user_id` aus `X-User-Id` ableiten und das Body-Feld vollständig ignorieren.

---

### ATK-02 — Score eines anderen Benutzers löschen (IDOR auf DELETE) ✅ SAFE

**Angriff**: Angreifer sendet `DELETE /leaderboards/1/scores/2` mit `X-User-Id: 1`, um den Score eines anderen Benutzers zu löschen.
**Ergebnis**: SAFE — `DELETE /scores/{userId}` beschränkt die Suche auf den authentifizierten Akteur. Der Pfad `userId` wird gegen `X-User-Id` abgeglichen; eine Nichtübereinstimmung gibt 404 zurück. Nur Admin-Rollen sollten beliebige Benutzer-Scores löschen können.

---

### ATK-03 — Score-Integer-Überlauf 🚫 BLOCKED

**Angriff**: Angreifer sendet `{"score": 9999999999999999999999}`, um den gespeicherten Integer zu überlaufen.
**Ergebnis**: BLOCKED — PHPs JSON-Parser begrenzt große Zahlen auf `PHP_INT_MAX` (~9,2×10^18). Integer-Typvalidierung lehnt Strings ab. SQL `INTEGER`-Speicherung ist 64-Bit; Überlauf ist in der Praxis nicht praktikabel.

---

### ATK-04 — Float-Score-Injektion 🚫 BLOCKED

**Angriff**: Angreifer sendet `{"score": 999.9}`, in der Hoffnung, dass ein Float über Integer-Scores sortiert.
**Ergebnis**: BLOCKED — Score wird als strikter Integer validiert. `999.9` wird mit 422 Unprocessable Entity abgelehnt, bevor es die DB erreicht.

---

### ATK-05 — SQL-Injection über Score 🚫 BLOCKED

**Angriff**: Angreifer sendet `{"score": "100; DROP TABLE scores--"}`, um die Datenbank zu beschädigen.
**Ergebnis**: BLOCKED — Score muss zuerst die Integer-Validierung bestehen. Parametrisierte Abfragen (`?`-Platzhalter) verhindern Injection auf der DB-Ebene, selbst wenn ein String die Validierung irgendwie bestehen würde.

---

### ATK-06 — Negativer Score, um einen anderen Benutzer zu benachteiligen 🚫 BLOCKED

**Angriff**: Angreifer sendet einen großen negativen Score für einen anderen Benutzer, um ihn ans Ende zu schieben.
**Ergebnis**: BLOCKED — Persönliche-Bestleistungs-Logik ersetzt einen gespeicherten Score nur, wenn der neue Score **höher** ist. Das Einreichen von -999999 für einen Benutzer mit Score 500 gibt `new_best: false` zurück und der gespeicherte Score bleibt unverändert. In Kombination mit der ATK-01-Mitigation wird Score-Injektion vollständig verhindert.

---

### ATK-07 — Limit-Injektion bei Rankings 🚫 BLOCKED

**Angriff**: Angreifer sendet `GET /rankings?limit=999999`, um die gesamte Bestenliste in einer Anfrage herunterzuladen.
**Ergebnis**: BLOCKED — `limit` wird mit `ctype_digit` validiert und bei `MAX_LIMIT` (z.B. 100) begrenzt. Anfragen, die die Grenze überschreiten → 422.

---

### ATK-08 — Fehlender X-User-Id bei authentifizierten Endpunkten 🚫 BLOCKED

**Angriff**: Angreifer lässt `X-User-Id` bei `GET /rankings/me` oder `DELETE` weg, um die Akteur-Validierung zu umgehen.
**Ergebnis**: BLOCKED — Beide Endpunkte geben 400 zurück, wenn `X-User-Id` fehlt oder leer ist.

---

### ATK-09 — Nicht-Integer-X-User-Id-Header-Injektion 🚫 BLOCKED

**Angriff**: Angreifer sendet `X-User-Id: 1 OR 1=1`, um SQL durch den Header zu injizieren.
**Ergebnis**: BLOCKED — `X-User-Id` wird mit `ctype_digit` validiert; jedes Nicht-Ziffer-Zeichen → 400. Der Wert erreicht niemals SQL, ohne die Integer-Validierung zu bestehen.

---

### ATK-10 — Score für nicht-existente Bestenliste 🚫 BLOCKED

**Angriff**: Angreifer fälscht `leaderboard_id = 9999`, in der Hoffnung, Bestenlisten-Kontrollen zu umgehen.
**Ergebnis**: BLOCKED — Bestenlisten-Existenz wird vor der Score-Einfügung geprüft. Unbekannte Bestenliste → 404.

---

### ATK-11 — Niedrigeren Score nach Löschung erneut einreichen 🚫 BLOCKED

**Angriff**: Angreifer löscht seinen Score, dann reicht er einen aufgeblasenen Wert ein, um den persönlichen Bestleistungsschutz zurückzusetzen.
**Ergebnis**: BLOCKED — Nach der Löschung wird die Zeile entfernt; die nächste Einreichung ist ein frischer Eintrag (`new_best: true`). Dies ist das erwartete Verhalten. Wenn historische Unveränderlichkeit erforderlich ist, Soft-Delete (`deleted_at`) verwenden und die vorherige Bestleistung beibehalten, um Wiedereinreichung zu blockieren.

---

### ATK-12 — Gleichzeitige Score-Einreichungen (Race Condition) 🚫 BLOCKED

**Angriff**: Zwei Anfragen senden gleichzeitig einen Score für denselben Benutzer, bevor eine davon committet.
**Ergebnis**: BLOCKED — `UNIQUE(leaderboard_id, user_id)` und ein atomares `INSERT OR REPLACE` / `UPDATE WHERE score < new_score` stellen sicher, dass es auf DB-Ebene nur einen Gewinner gibt. SQLite serialisiert Schreibvorgänge; MySQL/PostgreSQL verwenden Zeilensperrung.

---

### ATK Summary

| ID | Angriff | Ergebnis |
|----|---------|----------|
| ATK-01 | Score für einen anderen Benutzer einreichen (Body-IDOR) | ⚠️ EXPOSED |
| ATK-02 | Score eines anderen Benutzers löschen | ✅ SAFE |
| ATK-03 | Score-Integer-Überlauf | 🚫 BLOCKED |
| ATK-04 | Float-Score-Injektion | 🚫 BLOCKED |
| ATK-05 | SQL-Injection über Score | 🚫 BLOCKED |
| ATK-06 | Negativer Score zur Benachteiligung | 🚫 BLOCKED |
| ATK-07 | Limit-Injektion bei Rankings | 🚫 BLOCKED |
| ATK-08 | Fehlender Akteur-Header | 🚫 BLOCKED |
| ATK-09 | Nicht-Integer-X-User-Id-Header-Injektion | 🚫 BLOCKED |
| ATK-10 | Score für nicht-existente Bestenliste | 🚫 BLOCKED |
| ATK-11 | Score nach Löschung erneut einreichen | 🚫 BLOCKED |
| ATK-12 | Gleichzeitiges Score-Update-Rennen | 🚫 BLOCKED |

**10 BLOCKED, 1 SAFE, 1 EXPOSED** — Score-Einreichung muss verifizieren, dass der Akteur mit `user_id` übereinstimmt. Benutzeridentität aus `X-User-Id` ableiten; `user_id` niemals aus dem Request-Body akzeptieren.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| `user_id` im Request-Body ohne Akteur-Prüfung vertrauen | Jeder Benutzer kann Scores im Namen anderer einreichen |
| Alle Einreichungen statt nur persönlicher Bestleistung speichern | DB wächst unbegrenzt; Ranking wird mehrdeutig |
| Float-Scores erlauben | Float-Vergleich in SQL erzeugt unerwartete Sortierreihenfolge |
| Kein `UNIQUE(leaderboard_id, user_id)`-Constraint | Doppelte Zeilen blasen den scheinbaren Rang eines Benutzers auf |
| 200 mit leerer Liste für unbekannte Bestenliste zurückgeben | Maskiert Fehlkonfiguration; 404 für unbekannte Ressourcen |
| Kein Limit für `/rankings?limit=` | Full-Table-Scan bei großen Bestenlisten verursacht DoS |
