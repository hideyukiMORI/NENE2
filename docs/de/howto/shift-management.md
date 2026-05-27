# How-to: Schichtverwaltungs-API

> **FT-Referenz**: FT43 (`NENE2-FT/shiftlog`) — Mitarbeiter-Dienstplan-API
> **VULN**: FT225 — Sicherheits- und Schwachstellenbewertung (V-01 bis V-12)

Demonstriert eine Mitarbeiter-Dienstplan-API mit Überschneidungserkennung, transaktionsgebundenen
Prüfungen, ISO-8601-Datumsvergleichen und benutzerdefinierten Exception-Handlern für Domänenfehler.
Der VULN-Abschnitt bewertet systematisch jede Angriffsfläche und dokumentiert jeden Befund.

---

## Routen

| Methode   | Pfad                          | Beschreibung                                    |
|-----------|-------------------------------|-------------------------------------------------|
| `GET`     | `/employees`                  | Mitarbeiter auflisten (paginiert)               |
| `POST`    | `/employees`                  | Mitarbeiter erstellen                           |
| `GET`     | `/employees/{id}`             | Einzelnen Mitarbeiter abrufen                   |
| `GET`     | `/employees/{id}/shifts`      | Schichten eines Mitarbeiters auflisten (paginiert) |
| `POST`    | `/shifts`                     | Schicht einplanen (mit Überschneidungsprüfung)  |
| `GET`     | `/shifts/{id}`                | Einzelne Schicht abrufen                        |
| `DELETE`  | `/shifts/{id}`                | Schicht löschen                                 |
| `GET`     | `/schedule`                   | Schichten in einem Datumsfenster (`?from=&to=`) |
| `GET`     | `/summary/weekly`             | Stunden pro Mitarbeiter pro Woche               |
| `GET`     | `/summary/overtime`           | Mitarbeiter über einem Stundenschwellenwert     |

---

## Mitarbeiter erstellen

```php
// POST /employees
$body = [
    'name'        => 'Alice',    // Pflichtfeld, nicht-leerer String
    'role'        => 'Barista',  // Pflichtfeld, nicht-leerer String
    'hourly_rate' => 18.50,      // Pflichtfeld, numerisch > 0
];
```

`is_int()` / `is_string()` — strikte JSON-Typprüfungen werden angewendet. Leere Zeichenketten
werden nach `trim()` abgelehnt.

```php
if (!isset($body['hourly_rate'])
    || !is_numeric($body['hourly_rate'])
    || (float) $body['hourly_rate'] <= 0) {
    $errors[] = new ValidationError('hourly_rate', 'hourly_rate must be a positive number.', 'required');
}
```

> **Hinweis**: Das Schema enthält auch `CHECK(hourly_rate > 0)` auf DB-Ebene als
> Defense-in-Depth-Absicherung. Zunächst auf Anwendungsebene validieren, um ein korrektes 422 zurückzugeben.

---

## Schichten mit Überschneidungserkennung einplanen

Die Überschneidungserkennung läuft innerhalb einer Datenbanktransaktion, um Race Conditions zu verhindern:

```php
return $this->txManager->transactional(
    function (DatabaseQueryExecutorInterface $tx) use ($employeeId, $startsAt, $endsAt, $location, $now): Shift {
        $txRepo   = new self($tx, $this->txManager);
        $employee = $txRepo->findEmployeeById($employeeId);

        // Überschneidung: jede vorhandene Schicht, die [$startsAt, $endsAt) schneidet
        $overlap = $tx->fetchOne(
            "SELECT id FROM shifts
             WHERE employee_id = ?
               AND starts_at < ?
               AND ends_at   > ?",
            [$employeeId, $endsAt, $startsAt],
        );

        if ($overlap !== null) {
            throw new ShiftOverlapException($employee->name, $startsAt, $endsAt);
        }

        $id = $tx->insert(
            'INSERT INTO shifts (employee_id, starts_at, ends_at, location, created_at) VALUES (?, ?, ?, ?, ?)',
            [$employeeId, $startsAt, $endsAt, $location, $now],
        );
        // ...
    },
);
```

Die Überschneidungsbedingung `starts_at < $endsAt AND ends_at > $startsAt` behandelt korrekt alle
vier Überschneidungskonfigurationen (teilweise von links, teilweise von rechts, enthalten und enthaltend).

**Warum transaktional?** Ohne Transaktion können zwei gleichzeitige Anfragen beide die Überschneidungsprüfung
gleichzeitig bestehen und widersprüchliche Schichten erstellen. Die Transaktion serialisiert die
Lesen-Prüfen-Schreiben-Sequenz.

---

## Validierung: ends_at > starts_at

Die Anwendung validiert die Zeitreihenfolge vor dem DB-Zugriff:

```php
if ($endsAt <= $startsAt) {
    throw new ValidationException([
        new ValidationError('ends_at', 'ends_at must be after starts_at.', 'invalid_range'),
    ]);
}
```

Das Schema fügt `CHECK(ends_at > starts_at)` als Absicherung hinzu. Beide Schichten zusammen
stellen sicher, dass ungültige Bereiche nie den Datenspeicher erreichen.

---

## ISO-8601-Datumszeichenketten-Vergleich

Schichtzeiten werden als ISO-8601-Zeichenketten (`2026-05-27T09:00:00+09:00`) gespeichert und
in SQL lexikografisch verglichen. Dies funktioniert korrekt **nur wenn alle Zeiten denselben
Zeitzonenversatz oder UTC verwenden**. Vergleiche mit gemischten Offsets können falsche Ergebnisse liefern:

```
"2026-05-27T09:00:00+09:00" < "2026-05-27T01:00:00Z"  → falsch (identischer Zeitpunkt)
```

**Empfehlung**: Alle Datetimes vor der Speicherung auf UTC normalisieren:

```php
$utc      = new \DateTimeZone('UTC');
$startsAt = (new \DateTimeImmutable($raw))->setTimezone($utc)->format(\DateTimeInterface::ATOM);
```

---

## Benutzerdefinierte Exception → HTTP-Response-Zuordnung

Domänen-Exceptions werden über Handler auf strukturierte Problem-Details-Responses abgebildet:

```php
final readonly class ShiftOverlapExceptionHandler implements DomainExceptionHandlerInterface
{
    public function supports(\Throwable $exception): bool
    {
        return $exception instanceof ShiftOverlapException;
    }

    public function handle(\Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        return $this->factory->create(
            $request,
            'shift-overlap',
            'Shift overlaps with an existing shift.',
            409,
            $exception->getMessage(),
        );
    }
}
```

Separate Handler existieren für `ShiftNotFoundException` → 404, `EmployeeNotFoundException` → 404
und `ShiftOverlapException` → 409. Die Registrierung in der `RuntimeApplicationFactory` hält
Controller frei von `try/catch`-Boilerplate.

---

## Aggregatabfragen: Wochenzusammenfassung und Überstunden

```php
// GET /summary/weekly?from=2026-05-19&to=2026-05-25
// GET /summary/overtime?from=2026-05-19&to=2026-05-25&threshold=40
```

Der Überstundenschwellenwert ist standardmäßig 40 Stunden:

```php
$threshold = (float) (QueryStringParser::int($request, 'threshold') ?? 40);
if ($threshold <= 0) {
    throw new ValidationException([...]);
}
```

Hinweis: `QueryStringParser::int()` wird zuerst verwendet (lehnt nicht-numerische Zeichenketten ab),
dann wird auf `float` gecastet. Dies verhindert, dass `NaN` / `Infinity` die Geschäftsschicht erreicht.

---

## Schema: Kaskaden-Delete und DB-Ebene-Constraints

```sql
CREATE TABLE employees (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    role        TEXT    NOT NULL,
    hourly_rate REAL    NOT NULL CHECK(hourly_rate > 0),
    created_at  TEXT    NOT NULL
);

CREATE TABLE shifts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
    starts_at   TEXT    NOT NULL,
    ends_at     TEXT    NOT NULL,
    location    TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    CHECK(ends_at > starts_at)
);
```

`ON DELETE CASCADE` entfernt die Schichten eines Mitarbeiters, wenn dieser gelöscht wird.
DB-Ebene-`CHECK`-Constraints sind Defense-in-Depth-Absicherungen, nicht die primäre
Validierungsschicht — die Anwendungsebenen-Validierung muss 422 zurückgeben, bevor irgendein DB-INSERT erfolgt.

---

## VULN — Sicherheitsbewertung (FT225)

Jeder Befund dokumentiert den Angriffsvektor, das beobachtete Ergebnis und das Urteil:
**BLOCKIERT** (sicher), **EXPONIERT** (echte Schwachstelle), **TEILWEISE EXPONIERT**
oder **BY DESIGN AKZEPTIERT**.

### V-01 — Keine Authentifizierung auf einem beliebigen Endpunkt

**Angriff**: Mitarbeiter erstellen, Schichten einplanen oder löschen ohne Anmeldedaten.

```http
POST /employees
{"name": "Attacker", "role": "Ghost", "hourly_rate": 0.01}

DELETE /shifts/1
```

**Beobachtet**: Beide sind erfolgreich. Kein Token, keine Session und kein API-Key ist erforderlich.

**Urteil**: **EXPONIERT** (by design für FT43 Demo).
Produktive Planungssysteme MÜSSEN Mutationen hinter Authentifizierung absichern.
`MachineApiKeyMiddleware` (env: `NENE2_MACHINE_API_KEY`) oder JWT Bearer verwenden.

---

### V-02 — Keine Autorisierung: Jeder kann jede Schicht löschen

**Angriff**: Eine Schicht löschen, die einem anderen Mitarbeiter gehört, ohne Eigentümlichkeitsprüfung.

```http
DELETE /shifts/1   # gelingt für jeden authentifizierten oder nicht-authentifizierten Aufrufer
```

**Beobachtet**: `204 No Content` unabhängig von der Aufrufer-Identität.

**Urteil**: **EXPONIERT** (by design für FT43 Demo).
Eine Manager/Admin-Rollenprüfung vor dem Löschen hinzufügen oder Schichten an einen anfragenden Benutzer knüpfen.

---

### V-03 — SQL-Injection via parametrisierte Abfragen

**Angriff**: SQL über `name`, `role`, `starts_at` oder `location` einschleusen.

```json
{"name": "x'; DROP TABLE employees; --", "role": "Admin", "hourly_rate": 1}
{"starts_at": "2026-01-01' OR '1'='1", "ends_at": "2026-01-02", "employee_id": 1}
```

**Beobachtet**: Mitarbeiter wird mit der Injection-Zeichenkette als Namen erstellt. `starts_at` der Schicht
wird in einer parametrisierten Abfrage verwendet, so dass keine SQL-Injection stattfindet.

**Urteil**: **BLOCKIERT** — alle Abfragen verwenden PDO-parametrisierte Statements. Die gespeicherte
Zeichenkette ist in der DB harmlos; das einzige Risiko wäre, wenn sie später als HTML gerendert würde.

---

### V-04 — Race Condition bei der Schicht-Überschneidungserkennung

**Angriff**: Zwei gleichzeitige `POST /shifts`-Anfragen mit überlappenden Fenstern für
denselben Mitarbeiter senden.

**Beobachtet**: Die Überschneidungsprüfung läuft innerhalb von `transactional()`. SQLite serialisiert
Schreibvorgänge mit WAL-Mode-Locking; MySQL/PostgreSQL verwenden `REPEATABLE READ` oder `SERIALIZABLE`-
Isolation, wenn der Transaktions-Manager korrekt konfiguriert ist. Beide gleichzeitigen Inserts
können nicht beide die Überschneidungsprüfung bestehen.

**Urteil**: **BLOCKIERT** — transaktionale Überschneidungsprüfung verhindert Doppelbuchungen unter
Gleichzeitigkeit. Den Isolationsgrad gemäß der DB-Engine verifizieren; der WAL-Standardmodus von
SQLite ist für Single-Node-Deployments ausreichend.

---

### V-05 — ends_at ≤ starts_at wird akzeptiert

**Angriff**: Eine Schicht einreichen, bei der die Endzeit vor oder gleich der Startzeit liegt.

```json
{"employee_id": 1, "starts_at": "2026-05-27T10:00:00Z", "ends_at": "2026-05-27T09:00:00Z"}
{"employee_id": 1, "starts_at": "2026-05-27T10:00:00Z", "ends_at": "2026-05-27T10:00:00Z"}
```

**Beobachtet**: `422 Unprocessable Entity` — die Anwendung vergleicht Zeichenketten (`$endsAt <= $startsAt`)
vor dem Einfügen. Das DB-`CHECK(ends_at > starts_at)` ist eine Absicherung.

**Urteil**: **BLOCKIERT** — Zwei-Schichten-Validierung (Anwendung + DB-Constraint).

---

### V-06 — hourly_rate Validierungslücke

**Angriff**: Negativen, null oder String-Wert für `hourly_rate` einreichen.

```json
{"name": "X", "role": "Y", "hourly_rate": -10}
{"name": "X", "role": "Y", "hourly_rate": 0}
{"name": "X", "role": "Y", "hourly_rate": "free"}
```

**Beobachtet**:
- Negativ/null: Die Anwendung validiert `hourly_rate > 0` NICHT auf Controller-Ebene.
  Ein negativer Wert umgeht die App-Prüfung und trifft auf das DB-`CHECK(hourly_rate > 0)`,
  das eine DB-Exception auslöst. Ohne einen expliziten Handler wird daraus ein 500.
- String `"free"`: `is_numeric()` gibt false zurück, daher wird dies mit 422 abgelehnt.

**Urteil**: **TEILWEISE EXPONIERT** — Anwendungsebenen-Validierung vor dem DB-Insert hinzufügen:
```php
if (!isset($body['hourly_rate'])
    || !is_numeric($body['hourly_rate'])
    || (float) $body['hourly_rate'] <= 0) {
    $errors[] = new ValidationError('hourly_rate', 'hourly_rate must be a positive number.', 'out_of_range');
}
```

---

### V-07 — Semantisch ungültige ISO-8601-Datetime

**Angriff**: Eine Schicht mit einer strukturell plausiblen, aber kalenderungültigen Datetime einreichen.

```json
{"starts_at": "2026-02-30T00:00:00Z", "ends_at": "2026-02-30T08:00:00Z", "employee_id": 1}
```

**Beobachtet**: Wird akzeptiert und gespeichert. Die Anwendung prüft `trim() === ''`, parst das Datum
jedoch nicht. `DateTimeImmutable` normalisiert `2026-02-30` stillschweigend auf `2026-03-02`,
was den gespeicherten Wert verfälscht.

**Urteil**: **EXPONIERT** — einen Round-Trip-Check für `starts_at` und `ends_at` hinzufügen:
```php
$dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $raw);
if ($dt === false || $dt->format(DateTimeInterface::ATOM) !== $raw) {
    $errors[] = new ValidationError('starts_at', 'starts_at must be a valid ISO 8601 datetime.', 'invalid_format');
}
```

---

### V-08 — Unbegrenzter Datumsbereich in Aggregatabfragen

**Angriff**: Eine Zusammenfassung über einen beliebig großen Datumsbereich anfordern, um den Speicher
zu erschöpfen oder eine langsame Abfrage zu verursachen.

```http
GET /summary/weekly?from=1900-01-01&to=2099-12-31
```

**Beobachtet**: Die Abfrage läuft über alle Zeilen in der Tabelle. Bei einem großen Datensatz
kann dies zu übermäßigem Speicherverbrauch oder einer mehrsekundigen Antwort führen.

**Urteil**: **EXPONIERT** — den maximal erlaubten Bereich (z.B. 90 Tage) auf Controller-Ebene begrenzen:
```php
$maxDays = 90;
$diff    = (new DateTimeImmutable($to))->diff(new DateTimeImmutable($from));
if ($diff->days > $maxDays) {
    return $this->json->create(['error' => "Date range must not exceed {$maxDays} days."], 422);
}
```

---

### V-09 — Unbegrenzter Mitarbeitername / Rolle Länge

**Angriff**: Einen Mitarbeiter mit einem Namen oder einer Rolle von Zehntausenden von Zeichen erstellen.

```json
{"name": "AAAA... (50000 Zeichen)", "role": "Y", "hourly_rate": 10}
```

**Beobachtet**: `201 Created` — SQLite TEXT ist unbegrenzt; die Zeile wird eingefügt.

**Urteil**: **EXPONIERT** — `mb_strlen()`-Prüfungen hinzufügen und 422 zurückgeben:
```php
if (mb_strlen($name) > 100) {
    $errors[] = new ValidationError('name', 'name must not exceed 100 characters.', 'max_length');
}
```

---

### V-10 — Unbegrenzte Standort-Zeichenkette

**Angriff**: Eine Schicht mit einer Standort-Zeichenkette beliebiger Länge einplanen.

```json
{"employee_id": 1, "starts_at": "...", "ends_at": "...", "location": "BBBB... (50000 Zeichen)"}
```

**Beobachtet**: `201 Created` — kein Längenlimit wird erzwungen.

**Urteil**: **EXPONIERT** — `mb_strlen($location) <= 200`-Prüfung hinzufügen.

---

### V-11 — XSS-Payload in Name / Rolle / Standort

**Angriff**: Ein `<script>`-Tag in einem beliebigen Freitext-Feld speichern.

```json
{"name": "<script>alert(1)</script>", "role": "Admin", "hourly_rate": 1}
```

**Beobachtet**: `201 Created`. Wert wird unverändert in JSON-Antworten zurückgegeben.

**Urteil**: **BY DESIGN AKZEPTIERT** — dies ist eine JSON API; Escaping liegt in der Verantwortung
des HTML-Rendering-Clients. Der Server gibt kein HTML aus diesen Feldern aus.
Den Vertrag in der OpenAPI-Spezifikation dokumentieren.

---

### V-12 — Nicht-numerische Pfad-IDs

**Angriff**: Nicht-Ziffer- oder negative Werte als `{id}` übergeben.

```http
GET /shifts/abc
GET /shifts/-1
DELETE /employees/0
```

**Beobachtet**: `404 Not Found` in jedem Fall. `(int) "abc"` = `0`; keine Schicht/kein Mitarbeiter
mit ID 0 oder negativ existiert, also wirft `findShiftById(0)` `ShiftNotFoundException`,
die der Handler auf 404 abbildet.

**Urteil**: **BLOCKIERT** in der Praxis. Hinweis: `(int) "9abc"` = `9` — wenn ein Datensatz mit
ID 9 existiert, würde er zurückgegeben. `ctype_digit()` für strikte Pfad-ID-Validierung verwenden,
wenn der Unterschied wichtig ist.

---

## VULN-Zusammenfassung

| # | Angriffsvektor | Urteil |
|---|----------------|--------|
| V-01 | Keine Authentifizierung | EXPONIERT (by design) |
| V-02 | Keine Autorisierung / jede Schicht löschbar | EXPONIERT (by design) |
| V-03 | SQL-Injection | BLOCKIERT |
| V-04 | Überschneidungs-Race-Condition | BLOCKIERT |
| V-05 | ends_at ≤ starts_at | BLOCKIERT |
| V-06 | Negativer hourly_rate umgeht App-Prüfung | TEILWEISE EXPONIERT |
| V-07 | Semantisch ungültige ISO-8601-Datetime | EXPONIERT |
| V-08 | Unbegrenzter Datumsbereich in Aggregatabfragen | EXPONIERT |
| V-09 | Unbegrenzte Mitarbeitername/Rolle | EXPONIERT |
| V-10 | Unbegrenzte Standort-Zeichenkette | EXPONIERT |
| V-11 | XSS-Payload-Speicherung | BY DESIGN AKZEPTIERT |
| V-12 | Nicht-numerische Pfad-IDs | BLOCKIERT |

**Echte Schwachstellen vor der Produktion beheben**:
1. **V-01/02** — Authentifizierung und rollenbasierte Autorisierung hinzufügen
2. **V-06** — `hourly_rate > 0`-Validierung auf Anwendungsebene hinzufügen
3. **V-07** — ISO-8601-Round-Trip-Validierung für Datetime-Felder hinzufügen
4. **V-08** — Maximalen Datumsbereich in Aggregat-Endpunkten begrenzen (z.B. 90 Tage)
5. **V-09/10** — `mb_strlen()`-Maximallängen-Prüfungen für alle Freitext-Felder hinzufügen

---

## Verwandte Anleitungen

- [`notification-inbox.md`](notification-inbox.md) — IDOR-Schutzmuster (404 bei unbefugtem Lesen/Schreiben)
- [`prevent-double-booking.md`](prevent-double-booking.md) — Transaktionale Doppelbuchungs-Prävention
- [`expense-tracker.md`](expense-tracker.md) — ISO-8601-Round-Trip-Datumsvalidierung
- [`resource-booking.md`](resource-booking.md) — Datumsbereichsbegrenzung und Zeitfenster-Abfragen
