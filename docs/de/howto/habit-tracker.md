# How-to: Habit Tracker API

> **FT-Referenz**: FT24 (`NENE2-FT/habitlog`) — Habit-Tracking-API mit Streak-Berechnung
> **ATK**: FT224 — Cracker-Mindset-Angriffstest (ATK-01 bis ATK-12)

Demonstriert eine Habit-Tracking-REST-API mit Streak-Berechnung, doppelter Abschlussschutz (409 Conflict) und Frequenz-Allowlisting. Der ATK-Abschnitt dokumentiert jede Angriffsfläche, die der Cracker-Mindset findet, und notiert, ob jede verteidigt oder exponiert ist.

---

## Routen

| Methode   | Pfad                          | Beschreibung                                      |
|-----------|-------------------------------|---------------------------------------------------|
| `GET`     | `/habits`                     | Alle Habits auflisten (`?frequency=`)             |
| `POST`    | `/habits`                     | Habit erstellen                                   |
| `GET`     | `/habits/{id}`                | Einzelnes Habit abrufen                           |
| `DELETE`  | `/habits/{id}`                | Habit löschen (kaskadiert)                        |
| `POST`    | `/habits/{id}/completions`    | Abschluss erfassen (idempotent auf Datums-Ebene)  |
| `GET`     | `/habits/{id}/completions`    | Abschlüsse für ein Habit auflisten                |
| `GET`     | `/habits/{id}/streak`         | Aktueller Streak (`?today=YYYY-MM-DD`)            |

---

## Habits erstellen

```php
// POST /habits
$body = [
    'name'        => 'Morgenläufe',     // erforderlich, nicht leerer String
    'description' => '5 km laufen',     // optional
    'frequency'   => 'daily',           // 'daily' | 'weekly' | 'monthly'
];
```

`frequency` wird gegen eine explizite Allowlist validiert. Jeder andere Wert gibt 422 zurück.

```php
private function createHabit(ServerRequestInterface $req): mixed
{
    $body      = JsonRequestBodyParser::parse($req);
    $name      = isset($body['name']) ? trim((string) $body['name']) : '';
    $frequency = isset($body['frequency']) ? (string) $body['frequency'] : 'daily';

    $errors = [];
    if ($name === '') {
        $errors[] = new ValidationError('name', 'Name must not be empty.', 'required');
    }

    $validFrequencies = ['daily', 'weekly', 'monthly'];
    if (!in_array($frequency, $validFrequencies, true)) {
        $errors[] = new ValidationError('frequency', 'Frequency must be daily, weekly, or monthly.', 'invalid_value');
    }

    if ($errors !== []) {
        throw new ValidationException($errors);
    }
    // ...
}
```

---

## Abschlüsse mit doppeltem Schutz erfassen

Abschlüsse sind durch `(habit_id, completed_on)` via eine `UNIQUE`-Constraint gekennzeichnet. Ein zweiter POST für dasselbe Datum gibt **409 Conflict** zurück, ohne die Datenbankzeile zu berühren.

```sql
-- schema.sql
UNIQUE(habit_id, completed_on)
```

```php
public function complete(int $habitId, string $completedOn, string $note): Completion
{
    try {
        $this->executor->execute(
            'INSERT INTO completions (habit_id, completed_on, note) VALUES (?, ?, ?)',
            [$habitId, $completedOn, $note],
        );
    } catch (DatabaseConnectionException $e) {
        $previous = $e->getPrevious();
        if ($previous !== null && str_contains($previous->getMessage(), 'UNIQUE constraint failed')) {
            throw new AlreadyCompletedException($habitId, $completedOn);
        }
        throw $e;
    }

    return new Completion($this->executor->lastInsertId(), $habitId, $completedOn, $note);
}
```

Der Controller mappt `AlreadyCompletedException` → 409, bevor NECEs globaler Fehlerhandler es sieht, sodass die Antwort korrekt Problem Details verwendet.

---

## Streak-Berechnung

Streak zählt rückwärts von `$today` durch aufeinanderfolgende tägliche Abschlüsse.

```php
public function currentStreak(int $habitId, string $today): int
{
    $rows = $this->executor->fetchAll(
        'SELECT completed_on FROM completions WHERE habit_id = ? ORDER BY completed_on DESC',
        [$habitId],
    );

    $streak   = 0;
    $expected = new \DateTimeImmutable($today);

    foreach ($rows as $row) {
        $date = new \DateTimeImmutable((string) $row['completed_on']);
        if ($date->format('Y-m-d') !== $expected->format('Y-m-d')) {
            break;
        }
        $streak++;
        $expected = $expected->modify('-1 day');
    }

    return $streak;
}
```

`?today=YYYY-MM-DD` überschreibt das Referenzdatum, sodass Tests deterministisch sind ohne `date()` zu mocken.

---

## Datumsformat-Validierung

Das `completed_on`-Feld wird durch Regex validiert, nicht durch semantisches Parsen:

```php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $completedOn)) {
    throw new ValidationException([
        new ValidationError('completed_on', 'Date must be in YYYY-MM-DD format.', 'invalid_format'),
    ]);
}
```

Dies lehnt `"not-a-date"` korrekt ab, akzeptiert aber `"2026-02-30"`. Für strenge semantische Validierung einen `DateTimeImmutable`-Roundtrip hinzufügen:

```php
// Strengere Validierung (für Produktion empfohlen):
$dt = DateTimeImmutable::createFromFormat('Y-m-d', $completedOn);
if ($dt === false || $dt->format('Y-m-d') !== $completedOn) {
    throw new ValidationException([...]);
}
```

---

## Pfadparameter-Sicherheit

Pfad-`{id}` wird mit Zero-Fallback zu `int` gecastet:

```php
$id = (int) ($req->getAttribute(Router::PARAMETERS_ATTRIBUTE, [])['id'] ?? 0);
```

Nicht-numerische Strings werden zu `0`. Kein Habit mit `id = 0` existiert, sodass der Handler zu einer `null`-Prüfung fällt und 404 zurückgibt. Dies vermeidet den Bedarf nach `ctype_digit()` hier, aber beachten Sie, dass `(int) "9abc"` `9` ergibt — eine Route, die Nicht-Ziffern-Pfade ablehnen muss, sollte stattdessen `ctype_digit()` verwenden.

---

## Schema: Kaskaden-Delete

```sql
CREATE TABLE completions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    habit_id     INTEGER NOT NULL REFERENCES habits(id) ON DELETE CASCADE,
    completed_on TEXT    NOT NULL,
    note         TEXT    NOT NULL DEFAULT '',
    UNIQUE(habit_id, completed_on)
);
```

`ON DELETE CASCADE` stellt sicher, dass Abschlüsse entfernt werden, wenn das übergeordnete Habit gelöscht wird. Fremdschlüssel-Durchsetzung mit `PRAGMA foreign_keys = ON` bei SQLite aktivieren.

---

## ATK — Cracker-Angriffstest (FT224)

Jeder Befund unten dokumentiert einen Angriffsvektor, das beobachtete Ergebnis und das Urteil: **BLOCKED** (sicher), **EXPOSED** (echte Schwachstelle) oder **ACCEPTED BY DESIGN** (absichtlicher Kompromiss dokumentiert).

### ATK-01 — Keine Authentifizierung auf einem Endpunkt

**Angriff**: Habits erstellen, lesen oder löschen ohne Anmeldedaten.

```http
POST /habits
Content-Type: application/json

{"name": "Angreifer-Habit", "frequency": "daily"}
```

**Beobachtet**: `201 Created` — Erfolg ohne Token, Session oder Key.

**Urteil**: **EXPOSED** (by design für FT24 Demo).
Produktions-Habit-Tracker MÜSSEN Mutationen hinter Authentifizierung schützen.
NECEs `MachineApiKeyMiddleware` oder JWT-Bearer-Middleware deckt dies ab.

---

### ATK-02 — Keine Eigentümerschaft: Beliebiges Habit lesen/löschen

**Angriff**: Ohne zu wissen, wessen Habit es ist, alle Habits enumerieren und löschen.

```http
GET /habits         → listet alle Habits im System
DELETE /habits/1    → löscht Habit #1 unabhängig vom Ersteller
```

**Beobachtet**: `200 OK` bei Liste, `200 OK` beim Löschen.

**Urteil**: **EXPOSED** (by design für FT24 Demo).
Eine `user_id`-Spalte, Eigentümerschatsprüfung auf Schreibpfaden und 404 (nicht 403) bei unbefugtem Zugriff hinzufügen (IDOR-Schutz — siehe FT222 `notificationlog`).

---

### ATK-03 — SQL-Injection via parametrisierte Abfragen

**Angriff**: SQL durch `name`, `frequency` oder `completed_on` einschleusen.

```json
{"name": "x' OR '1'='1", "frequency": "daily"}
{"completed_on": "2026-01-01' OR '1'='1"}
```

**Beobachtet**: Name wörtlich gespeichert. Abschluss durch Datumsformat-Regex abgelehnt, bevor die DB-Schicht erreicht wird.

**Urteil**: **BLOCKED** — alle Abfragen verwenden PDO-parametrisierte Statements. Die Frequenz-Allowlist blockiert Injection über dieses Feld auf Anwendungsebene.

---

### ATK-04 — Semantisch ungültiges Datum akzeptiert

**Angriff**: Strukturell korrektes, aber kalendarisch ungültiges Datum einreichen.

```json
{"completed_on": "2026-02-30"}
{"completed_on": "2026-13-01"}
{"completed_on": "0000-00-00"}
```

**Beobachtet**: `201 Created` — Regex `^\d{4}-\d{2}-\d{2}$` passiert; PDO speichert den String wörtlich; `DateTimeImmutable` normalisiert es still (z. B. `2026-02-30` wird zu `2026-03-02`), was Streak-Zählungen korrumpiert.

**Urteil**: **EXPOSED** — Roundtrip-Prüfung hinzufügen:
```php
$dt = DateTimeImmutable::createFromFormat('Y-m-d', $completedOn);
if ($dt === false || $dt->format('Y-m-d') !== $completedOn) {
    throw new ValidationException([...]);
}
```

---

### ATK-05 — Nicht-numerische Pfad-IDs

**Angriff**: Nicht-Ziffern- oder negative Werte als `{id}` senden.

```http
GET  /habits/abc
GET  /habits/-1
GET  /habits/0
GET  /habits/1.5
```

**Beobachtet**: Alle geben `404 Not Found` zurück. `(int) "abc"` = `0`, `(int) "-1"` = `-1`, `(int) "1.5"` = `1`. Kein Habit existiert bei diesen IDs, also gibt `findById()` `null` zurück.

**Urteil**: **BLOCKED** in der Praxis (kein Habit mit ID ≤ 0 existiert). Jedoch ergibt `(int) "9abc"` = `9` — wenn ein Habit mit ID 9 existiert, würde es zurückgegeben. `ctype_digit()` für strikte Pfad-ID-Validierung verwenden, wenn der Unterschied wichtig ist.

---

### ATK-06 — Doppelter Abschluss am selben Datum

**Angriff**: Dasselbe `(habit_id, completed_on)` zweimal posten, um Streaks aufzublähen.

```http
POST /habits/1/completions {"completed_on": "2026-05-20"}
POST /habits/1/completions {"completed_on": "2026-05-20"}
```

**Beobachtet**: Zweite Anfrage gibt `409 Conflict` zurück — die UNIQUE-Constraint auf DB-Ebene löst aus, `AlreadyCompletedException` wird abgefangen, und eine Problem-Details-Antwort wird zurückgegeben.

**Urteil**: **BLOCKED** — die DB-Constraint ist der maßgebliche Guard; die Anwendungsschicht mappt es auf eine wohlgeformte 409.

---

### ATK-07 — XSS-Payload in name/note

**Angriff**: Ein Script-Tag in `name` oder `note` speichern.

```json
{"name": "<script>alert(document.cookie)</script>", "frequency": "daily"}
```

**Beobachtet**: `201 Created`. Der Payload wird wörtlich gespeichert und unverändert in JSON-Antworten zurückgegeben.

**Urteil**: **ACCEPTED BY DESIGN** — dies ist eine JSON-API; Escaping ist die Verantwortung des Rendering-Clients. Der Server produziert kein HTML aus diesen Feldern. Diesen Vertrag klar in der API-Spezifikation dokumentieren.

---

### ATK-08 — Extrem langer Habit-Name

**Angriff**: Einen Namen mit zehntausenden Zeichen senden, um Speicher zu erschöpfen oder langsame Serialisierung zu verursachen.

```php
'name' => str_repeat('A', 50_000)
```

**Beobachtet**: `201 Created` — kein Längenlimit wird auf Anwendungsebene durchgesetzt. SQLite TEXT ist unbegrenzt; die Zeile wird eingefügt.

**Urteil**: **EXPOSED** — eine Max-Längenprüfung hinzufügen (z. B. 200 Zeichen) im Validierungsblock des Controllers und 422 zurückgeben:
```php
if (mb_strlen($name) > 200) {
    $errors[] = new ValidationError('name', 'Name must not exceed 200 characters.', 'max_length');
}
```

---

### ATK-09 — Nur-Leerzeichen Habit-Name

**Angriff**: Einen Namen senden, der nur aus Leerzeichen besteht.

```json
{"name": "   "}
```

**Beobachtet**: `422 Unprocessable Entity` — `trim()` reduziert den Wert auf `''`, was den `required`-Validierungsfehler auslöst.

**Urteil**: **BLOCKED** — `trim()` vor der Leerzeichenprüfung deckt dies ab.

---

### ATK-10 — Streak-Manipulation via `?today=`-Query-Param

**Angriff**: Das Referenzdatum überschreiben, um einen historischen Streak zu beanspruchen.

```http
GET /habits/1/streak?today=2099-12-31
GET /habits/1/streak?today=not-a-date
```

**Beobachtet**: `today=2099-12-31` → Streak = 0 (keine Abschlüsse in der Zukunft). `today=not-a-date` → PHP `DateTimeImmutable` wirft intern eine Exception auf dem fehlerhaften Wert (wird zu 500 im Standard-Fehlerhandler).

**Urteil**: **TEILWEISE EXPOSED** — `today` mit einer Regex- oder Roundtrip-Prüfung validieren, bevor es an `currentStreak()` übergeben wird:
```php
$today = QueryStringParser::string($req, 'today') ?? date('Y-m-d');
$dt    = DateTimeImmutable::createFromFormat('Y-m-d', $today);
if ($dt === false || $dt->format('Y-m-d') !== $today) {
    $today = date('Y-m-d'); // auf Server-Datum zurückfallen
}
```

---

### ATK-11 — Nicht-existierendes Habit abschließen

**Angriff**: POST eines Abschlusses für eine Habit-ID, die nicht existiert.

```http
POST /habits/99999/completions
{"completed_on": "2026-05-20"}
```

**Beobachtet**: `404 Not Found` — `findById(99999)` gibt `null` zurück und der Controller gibt die Not-Found-Antwort zurück, bevor das INSERT versucht wird.

**Urteil**: **BLOCKED** — die Existenzprüfung erfolgt vor dem DB-Schreibvorgang.

---

### ATK-12 — Path Traversal / Injection in Query-Parametern

**Angriff**: Path-Traversal- oder Shell-Injection-Strings via `frequency`-Filter einschleusen.

```http
GET /habits?frequency=../../../etc/passwd
GET /habits?frequency='; DROP TABLE habits; --
```

**Beobachtet**: Beide geben `200 OK` mit einem leeren `habits`-Array zurück. Der `frequency`-Wert wird nur in `array_filter` mit einem strengen `===`-Vergleich gegen gespeicherte Werte verwendet. Keine DB-Abfrage wird daraus konstruiert.

**Urteil**: **BLOCKED** — Filter-by-Query-Param wird im PHP-Speicher angewendet, nicht als rohe SQL-`WHERE`-Klausel. Keine Datei-I/O oder Shell-Ausführung wird ausgelöst.

---

## ATK-Zusammenfassung

| # | Vektor | Urteil |
|---|--------|--------|
| ATK-01 | Keine Authentifizierung | EXPOSED (by design) |
| ATK-02 | Keine Eigentümerschaft / IDOR | EXPOSED (by design) |
| ATK-03 | SQL-Injection | BLOCKED |
| ATK-04 | Semantisch ungültiges Datum | EXPOSED |
| ATK-05 | Nicht-numerische Pfad-ID | BLOCKED |
| ATK-06 | Doppelter Abschluss | BLOCKED |
| ATK-07 | XSS-Payload-Speicherung | ACCEPTED BY DESIGN |
| ATK-08 | Unbegrenzter Namensstring | EXPOSED |
| ATK-09 | Nur-Leerzeichen-Name | BLOCKED |
| ATK-10 | `?today=`-Manipulation | TEILWEISE EXPOSED |
| ATK-11 | Nicht-existierender Habit-Abschluss | BLOCKED |
| ATK-12 | Path Traversal / Injection in QS | BLOCKED |

**Echte Schwachstellen, die vor Produktion behoben werden müssen**:
1. **ATK-01/02** — Authentifizierung und Eigentümerschaft hinzufügen
2. **ATK-04** — Semantische Datumsvalidierung hinzufügen (Roundtrip via `DateTimeImmutable`)
3. **ATK-08** — `mb_strlen()` Max-Längenprüfung auf `name`/`note` hinzufügen
4. **ATK-10** — `?today=` vor der Weitergabe an die Geschäftslogik validieren

---

## Verwandte Anleitungen

- [`notification-inbox.md`](notification-inbox.md) — IDOR-Schutz-Muster (404 bei unbefugtem Lesen)
- [`expense-tracker.md`](expense-tracker.md) — strikte `is_int()`-Typprüfungen und ISO-Datum-Roundtrip-Validierung
- [`session-management.md`](session-management.md) — Authentifizierungsschicht, die auf diesem Muster aufgebaut werden kann
