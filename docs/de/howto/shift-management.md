# Anleitung: Schichtverwaltungs-API

> **FT-Referenz**: FT43 (`NENE2-FT/shiftlog`) — Mitarbeiter-Schichtplanungs-API
> **VULN**: FT225 — Sicherheits-/Schwachstellen-Assessment (V-01 bis V-12)

Demonstriert eine Mitarbeiter-Schichtplanungs-API mit Überlappungserkennung, transaktionsbasierten
Prüfungen, ISO 8601-Datumsvergleichen und benutzerdefinierten Exception-Handlern für Domänenfehler.
Der VULN-Abschnitt bewertet systematisch jede Angriffsfläche und dokumentiert die Ergebnisse.

---

## Routen

| Methode   | Pfad                          | Beschreibung                              |
|-----------|-------------------------------|------------------------------------------|
| `GET`    | `/employees`                  | Mitarbeiter auflisten (paginiert)         |
| `POST`   | `/employees`                  | Mitarbeiter erstellen                     |
| `GET`    | `/employees/{id}`             | Einzelnen Mitarbeiter abrufen             |
| `GET`    | `/employees/{id}/shifts`      | Schichten eines Mitarbeiters auflisten (paginiert) |
| `POST`   | `/shifts`                     | Schicht einplanen (Überlappungsprüfung)   |
| `GET`    | `/shifts/{id}`                | Einzelne Schicht abrufen                  |
| `DELETE` | `/shifts/{id}`                | Schicht löschen                           |
| `GET`    | `/schedule`                   | Schichten in einem Datumsfenster (`?from=&to=`) |
| `GET`    | `/summary/weekly`             | Stunden pro Mitarbeiter pro Woche         |
| `GET`    | `/summary/overtime`           | Mitarbeiter über einer Stundenschwelle    |

---

## Mitarbeiter erstellen

```php
// POST /employees
$body = [
    'name'        => 'Alice',    // erforderlich, nicht-leerer String
    'role'        => 'Barista',  // erforderlich, nicht-leerer String
    'hourly_rate' => 18.50,      // erforderlich, numerisch > 0
];
```

Strikte JSON-Typprüfungen mit `is_int()` / `is_string()` werden angewendet. Leere Strings werden nach `trim()` abgelehnt.

```php
if (!isset($body['hourly_rate'])
    || !is_numeric($body['hourly_rate'])
    || (float) $body['hourly_rate'] <= 0) {
    $errors[] = new ValidationError('hourly_rate', 'hourly_rate must be a positive number.', 'required');
}
```

> **Hinweis**: Das Schema hat auch `CHECK(hourly_rate > 0)` auf DB-Ebene als Defense-in-Depth-Backstop. Auf App-Ebene zuerst validieren, um ein ordentliches 422 zurückzugeben.

---

## Schichten einplanen mit Überlappungserkennung

Die Überlappungserkennung läuft innerhalb einer Datenbanktransaktion, um Race Conditions zu verhindern:

```php
return $this->txManager->transactional(
    function (DatabaseQueryExecutorInterface $tx) use ($employeeId, $startsAt, $endsAt, $location, $now): Shift {
        $txRepo   = new self($tx, $this->txManager);
        $employee = $txRepo->findEmployeeById($employeeId);

        // Überlappung: jede vorhandene Schicht, die [$startsAt, $endsAt) überschneidet
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

Die Überlappungsbedingung `starts_at < $endsAt AND ends_at > $startsAt` behandelt alle vier Überlappungskonfigurationen korrekt (links teilweise, rechts teilweise, enthalten, enthaltend).

**Warum transaktional?** Ohne eine Transaktion können zwei gleichzeitige Anfragen beide die Überlappungsprüfung bestehen und konflikthafte Schichten erstellen. Die Transaktion serialisiert die Lesen-Prüfen-Schreiben-Sequenz.

---

## ends_at > starts_at Validierung

Die Anwendung validiert die Zeitreihenfolge vor der DB:

```php
if ($endsAt <= $startsAt) {
    throw new ValidationException([
        new ValidationError('ends_at', 'ends_at must be after starts_at.', 'invalid_range'),
    ]);
}
```

Das Schema fügt `CHECK(ends_at > starts_at)` als Backstop hinzu. Beide Ebenen zusammen stellen sicher, dass ungültige Bereiche nie den Datenspeicher erreichen.

---

## ISO 8601-Datumsstring-Vergleich

Schichtzeiten werden als ISO 8601-Strings (`2026-05-27T09:00:00+09:00`) gespeichert und lexikografisch in SQL verglichen. Dies funktioniert korrekt **nur wenn alle Zeiten denselben Zeitzonenoffset oder UTC verwenden**. Gemischte-Offset-Vergleiche können falsche Ergebnisse liefern:

```
"2026-05-27T09:00:00+09:00" < "2026-05-27T01:00:00Z"  → falsch (gleicher Moment)
```

**Empfehlung**: Alle Datetimes vor der Speicherung auf UTC normalisieren:

```php
$utc      = new \DateTimeZone('UTC');
$startsAt = (new \DateTimeImmutable($raw))->setTimezone($utc)->format(\DateTimeInterface::ATOM);
```

---

## Benutzerdefinierte Exception → HTTP-Antwort-Zuordnung

Domain-Exceptions werden über Handler auf strukturierte Problem-Details-Antworten abgebildet:

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

Separate Handler existieren für `ShiftNotFoundException` → 404, `EmployeeNotFoundException` → 404 und `ShiftOverlapException` → 409. Die Registrierung in `RuntimeApplicationFactory` hält Controller frei von `try/catch`-Boilerplate.

---

## Aggregat-Abfragen: Wochenzusammenfassung und Überstunden

```php
// GET /summary/weekly?from=2026-05-19&to=2026-05-25
// GET /summary/overtime?from=2026-05-19&to=2026-05-25&threshold=40
```

Die Überstundenschwelle ist standardmäßig 40 Stunden:

```php
$threshold = (float) (QueryStringParser::int($request, 'threshold') ?? 40);
if ($threshold <= 0) {
    throw new ValidationException([...]);
}
```

Hinweis: `QueryStringParser::int()` wird zuerst verwendet (lehnt nicht-numerische Strings ab), dann wird auf `float` gecastet. Dies verhindert, dass `NaN` / `Infinity` die Business-Layer erreichen.

---

## Schema: Cascade-Delete und DB-Level-Bedingungen

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

`ON DELETE CASCADE` entfernt die Schichten eines Mitarbeiters, wenn der Mitarbeiter gelöscht wird. DB-Level-`CHECK`-Bedingungen sind Defense-in-Depth-Backstops, nicht die primäre Validierungsebene — App-Level-Validierung muss 422 zurückgeben, bevor ein DB-INSERT ausgeführt wird.

---

## VULN — Sicherheitsassessment (FT225)

Jedes Ergebnis dokumentiert den Angriffsvektor, das beobachtete Ergebnis und das Urteil:
**BLOCKED** (sicher), **EXPOSED** (echte Schwachstelle), **PARTIALLY EXPOSED** (teilweise exponiert),
oder **ACCEPTED BY DESIGN** (akzeptiert nach Design).

### V-01 — Keine Authentifizierung bei keinem Endpunkt

**Angriff**: Mitarbeiter erstellen, Schichten planen oder Schichten ohne Anmeldedaten löschen.

```http
POST /employees
{"name": "Attacker", "role": "Ghost", "hourly_rate": 0.01}

DELETE /shifts/1
```

**Beobachtet**: Beide erfolgreich. Kein Token, keine Session und kein API-Key erforderlich.

**Urteil**: **EXPOSED** (nach Design für FT43-Demo).
Produktions-Planungssysteme MÜSSEN Mutationen hinter Authentifizierung sperren.
`MachineApiKeyMiddleware` (env: `NENE2_MACHINE_API_KEY`) oder JWT Bearer verwenden.

---

### V-02 — Keine Autorisierung: Jeder kann jede Schicht löschen

**Angriff**: Eine Schicht löschen, die einem anderen Mitarbeiter gehört, ohne Eigentümerschaftsprüfung.

```http
DELETE /shifts/1   # gelingt für jeden authentifizierten oder nicht-authentifizierten Aufrufer
```

**Beobachtet**: `204 No Content` unabhängig von der Aufruferidentität.

**Urteil**: **EXPOSED** (nach Design für FT43-Demo).
Eine Manager/Admin-Rollenprüfung vor dem Löschen hinzufügen oder Schichten an einen anfragenden Benutzer binden.

---

### V-03 — SQL-Injection über parametrisierte Abfragen

**Angriff**: SQL durch `name`, `role`, `starts_at` oder `location` injizieren.

```json
{"name": "x'; DROP TABLE employees; --", "role": "Admin", "hourly_rate": 1}
{"starts_at": "2026-01-01' OR '1'='1", "ends_at": "2026-01-02", "employee_id": 1}
```

**Beobachtet**: Mitarbeiter mit dem Injection-String als Namen erstellt. `starts_at` der Schicht wird in einer parametrisierten Abfrage verwendet, daher tritt keine SQL-Injection auf.

**Urteil**: **BLOCKED** — alle Abfragen verwenden PDO-parametrisierte Statements. Der gespeicherte String ist in der DB harmlos; das einzige Risiko wäre, wenn er später als HTML gerendert würde.

---

### V-04 — Race Condition bei der Schicht-Überlappungserkennung

**Angriff**: Zwei gleichzeitige `POST /shifts`-Anfragen mit überlappenden Fenstern für denselben Mitarbeiter senden.

**Beobachtet**: Die Überlappungsprüfung läuft innerhalb von `transactional()`. SQLite serialisiert Schreibvorgänge mit WAL-Mode-Locking; MySQL/PostgreSQL verwenden `REPEATABLE READ` oder `SERIALIZABLE`-Isolation, wenn der Transaction Manager korrekt konfiguriert ist. Beide gleichzeitigen Inserts können nicht beide die Überlappungsprüfung bestehen.

**Urteil**: **BLOCKED** — transaktionale Überlappungsprüfung verhindert Doppelbuchungen unter Nebenläufigkeit. Isolationslevel verifizieren, dass er zum DB-Engine passt; SQLites WAL-Standard ist für Single-Node-Deployments ausreichend.

---

### V-05 — ends_at ≤ starts_at akzeptiert

**Angriff**: Eine Schicht einreichen, bei der die Endzeit vor oder gleich der Startzeit liegt.

```json
{"employee_id": 1, "starts_at": "2026-05-27T10:00:00Z", "ends_at": "2026-05-27T09:00:00Z"}
{"employee_id": 1, "starts_at": "2026-05-27T10:00:00Z", "ends_at": "2026-05-27T10:00:00Z"}
```

**Beobachtet**: `422 Unprocessable Entity` — die App vergleicht Strings (`$endsAt <= $startsAt`) vor dem Einfügen. Das DB `CHECK(ends_at > starts_at)` ist ein Backstop.

**Urteil**: **BLOCKED** — zweischichtige Validierung (App + DB-Bedingung).

---

### V-06 — hourly_rate-Validierungslücke

**Angriff**: Einen negativen, null- oder String-Wert für `hourly_rate` einreichen.

```json
{"name": "X", "role": "Y", "hourly_rate": -10}
{"name": "X", "role": "Y", "hourly_rate": 0}
{"name": "X", "role": "Y", "hourly_rate": "free"}
```

**Beobachtet**:
- Negativ/null: Die Anwendung validiert `hourly_rate > 0` NICHT auf Controller-Ebene. Ein negativer Wert umgeht die App-Prüfung und trifft das DB `CHECK(hourly_rate > 0)`, das eine DB-Exception auslöst. Ohne expliziten Handler wird dies zu einem 500.
- String `"free"`: `is_numeric()` gibt false zurück, daher wird dies mit 422 abgelehnt.

**Urteil**: **PARTIALLY EXPOSED** — App-Layer-Validierung vor dem DB-Insert hinzufügen:
```php
if (!isset($body['hourly_rate'])
    || !is_numeric($body['hourly_rate'])
    || (float) $body['hourly_rate'] <= 0) {
    $errors[] = new ValidationError('hourly_rate', 'hourly_rate must be a positive number.', 'out_of_range');
}
```

---

### V-07 — Semantisch ungültiges ISO 8601-Datetime

**Angriff**: Eine Schicht mit einem strukturell plausiblen, aber kalendarisch ungültigen Datetime einreichen.

```json
{"starts_at": "2026-02-30T00:00:00Z", "ends_at": "2026-02-30T08:00:00Z", "employee_id": 1}
```

**Beobachtet**: Akzeptiert und gespeichert. Die Anwendung prüft `trim() === ''`, parst das Datum jedoch nicht. `DateTimeImmutable` normalisiert `2026-02-30` stillschweigend auf `2026-03-02` und korrumpiert so den gespeicherten Wert.

**Urteil**: **EXPOSED** — eine Roundtrip-Prüfung sowohl für `starts_at` als auch für `ends_at` hinzufügen:
```php
$dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $raw);
if ($dt === false || $dt->format(DateTimeInterface::ATOM) !== $raw) {
    $errors[] = new ValidationError('starts_at', 'starts_at must be a valid ISO 8601 datetime.', 'invalid_format');
}
```

---

### V-08 — Unbegrenzter Datumsbereich in Aggregat-Abfragen

**Angriff**: Eine Zusammenfassung über einen beliebig großen Datumsbereich anfordern, um den Speicher zu erschöpfen oder eine langsame Abfrage zu verursachen.

```http
GET /summary/weekly?from=1900-01-01&to=2099-12-31
```

**Beobachtet**: Abfrage läuft über alle Zeilen in der Tabelle. Bei einem großen Datensatz kann dies zu übermäßiger Speichernutzung oder einer mehrsekundigen Antwort führen.

**Urteil**: **EXPOSED** — den maximalen erlaubten Bereich auf Controller-Ebene begrenzen (z. B. 90 Tage):
```php
$maxDays = 90;
$diff    = (new DateTimeImmutable($to))->diff(new DateTimeImmutable($from));
if ($diff->days > $maxDays) {
    return $this->json->create(['error' => "Date range must not exceed {$maxDays} days."], 422);
}
```

---

### V-09 — Unbegrenzte Länge von Mitarbeitername / Rolle

**Angriff**: Einen Mitarbeiter mit einem Namen oder einer Rolle mit zehntausenden Zeichen erstellen.

```json
{"name": "AAAA... (50000 Zeichen)", "role": "Y", "hourly_rate": 10}
```

**Beobachtet**: `201 Created` — SQLite TEXT ist unbegrenzt; die Zeile wird eingefügt.

**Urteil**: **EXPOSED** — `mb_strlen()`-Prüfungen hinzufügen und 422 zurückgeben:
```php
if (mb_strlen($name) > 100) {
    $errors[] = new ValidationError('name', 'name must not exceed 100 characters.', 'max_length');
}
```

---

### V-10 — Unbegrenzter Ort-String

**Angriff**: Eine Schicht mit einem Ort-String beliebiger Länge einplanen.

```json
{"employee_id": 1, "starts_at": "...", "ends_at": "...", "location": "BBBB... (50000 Zeichen)"}
```

**Beobachtet**: `201 Created` — kein Längenlimit wird durchgesetzt.

**Urteil**: **EXPOSED** — `mb_strlen($location) <= 200`-Prüfung hinzufügen.

---

### V-11 — XSS-Payload in Name / Rolle / Ort

**Angriff**: Ein `<script>`-Tag in einem beliebigen Freitextfeld speichern.

```json
{"name": "<script>alert(1)</script>", "role": "Admin", "hourly_rate": 1}
```

**Beobachtet**: `201 Created`. Wert wird in JSON-Antworten unverändert zurückgegeben.

**Urteil**: **ACCEPTED BY DESIGN** — dies ist eine JSON-API; Escaping ist die Verantwortung des HTML-Rendering-Clients. Der Server gibt aus diesen Feldern kein HTML aus. Den Vertrag in der OpenAPI-Spezifikation dokumentieren.

---

### V-12 — Nicht-numerische Pfad-IDs

**Angriff**: Nicht-Ziffern- oder negative Werte als `{id}` übergeben.

```http
GET /shifts/abc
GET /shifts/-1
DELETE /employees/0
```

**Beobachtet**: `404 Not Found` in jedem Fall. `(int) "abc"` = `0`; keine Schicht/kein Mitarbeiter mit ID 0 oder negativ existiert, daher wirft `findShiftById(0)` `ShiftNotFoundException`, die der Handler auf 404 abbildet.

**Urteil**: **BLOCKED** in der Praxis. Hinweis: `(int) "9abc"` = `9` — wenn ein Datensatz mit ID 9 existiert, würde er zurückgegeben. `ctype_digit()` für strikte Pfad-ID-Validierung verwenden, wenn der Unterschied wichtig ist.

---

## VULN-Zusammenfassung

| # | Angriffsvektor | Urteil |
|---|----------------|--------|
| V-01 | Keine Authentifizierung | EXPOSED (nach Design) |
| V-02 | Keine Autorisierung / jede Schicht löschbar | EXPOSED (nach Design) |
| V-03 | SQL-Injection | BLOCKED |
| V-04 | Überlappungs-Race-Condition | BLOCKED |
| V-05 | ends_at ≤ starts_at | BLOCKED |
| V-06 | Negative hourly_rate umgeht App-Prüfung | PARTIALLY EXPOSED |
| V-07 | Semantisch ungültiges ISO 8601-Datetime | EXPOSED |
| V-08 | Unbegrenzter Datumsbereich in Aggregat-Abfragen | EXPOSED |
| V-09 | Unbegrenzte Mitarbeitername/Rolle | EXPOSED |
| V-10 | Unbegrenzter Ort-String | EXPOSED |
| V-11 | XSS-Payload-Speicherung | ACCEPTED BY DESIGN |
| V-12 | Nicht-numerische Pfad-IDs | BLOCKED |

**Echte Schwachstellen, die vor der Produktion behoben werden müssen**:
1. **V-01/02** — Authentifizierung und rollenbasierte Autorisierung hinzufügen
2. **V-06** — `hourly_rate > 0`-Validierung auf App-Ebene hinzufügen
3. **V-07** — ISO 8601-Roundtrip-Validierung für Datetime-Felder hinzufügen
4. **V-08** — Maximalen Datumsbereich in Aggregat-Endpunkten begrenzen (z. B. 90 Tage)
5. **V-09/10** — `mb_strlen()`-Max-Längen-Prüfungen für alle Freitextfelder hinzufügen

---

## Verwandte Anleitungen

- [`notification-inbox.md`](notification-inbox.md) — IDOR-Schutzmuster (404 bei nicht autorisiertem Lesen/Schreiben)
- [`prevent-double-booking.md`](prevent-double-booking.md) — transaktionale Doppelbuchungs-Prävention
- [`expense-tracker.md`](expense-tracker.md) — ISO 8601-Roundtrip-Datumsvalidierung
- [`resource-booking.md`](resource-booking.md) — Datumsbereichsbegrenzung und Zeitfenster-Abfragen
