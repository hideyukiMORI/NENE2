# Implementierungsleitfaden für CSV-Massenimport-API

## Überblick

Dieser Leitfaden erklärt, wie mit NENE2 eine CSV-Massenimport-API implementiert wird.
Zeilenweise Validierung, Teilerfolg, Fehlersammlung und Importverlaufsverwaltung werden als REST-API bereitgestellt.

---

## DB-Schema

```sql
CREATE TABLE import_jobs (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    filename      TEXT    NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'completed',
    total_rows    INTEGER NOT NULL DEFAULT 0,
    imported_rows INTEGER NOT NULL DEFAULT 0,
    failed_rows   INTEGER NOT NULL DEFAULT 0,
    errors        TEXT    NOT NULL DEFAULT '[]',
    created_at    TEXT    NOT NULL,
    completed_at  TEXT
);

CREATE TABLE imported_records (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    import_job_id INTEGER NOT NULL,
    name          TEXT    NOT NULL,
    email         TEXT    NOT NULL,
    age           INTEGER,
    created_at    TEXT    NOT NULL,
    FOREIGN KEY (import_job_id) REFERENCES import_jobs(id)
);
```

---

## Endpunkt-Design

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| POST | `/imports` | CSV importieren (synchrone Verarbeitung, Teilerfolg-Unterstützung) |
| GET | `/imports` | Import-Jobs auflisten |
| GET | `/imports/{importId}` | Import-Ergebnis + Datensätze abrufen |

### Anfrageformat

```json
POST /imports
{
  "csv": "name,email,age\nAlice,alice@example.com,30\nBob,bob@example.com,25",
  "filename": "users.csv"
}
```

CSV wird als String im `csv`-Feld des JSON-Bodys gesendet. Dadurch ist das Testen im Standard-JSON-API-Flow einfach.

---

## Implementierung

### CsvImporter (Reiner Parser)

```php
class CsvImporter
{
    private const array REQUIRED_HEADERS = ['name', 'email', 'age'];

    /** @return array{rows: list<...>, errors: list<...>} */
    public function parse(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        // ...

        foreach ($lines as $i => $line) {
            // PHP 8.4: $escape-Parameter muss explizit angegeben werden, sonst Deprecation
            $fields = str_getcsv($line, ',', '"', '\\');
            $fields = array_map(static fn(?string $f): string => trim((string) ($f ?? '')), $fields);

            if ($i === 0) {
                continue; // Header überspringen
            }
            // ... Validierung und Sammlung
        }
    }

    public function validateHeader(string $csv): bool
    {
        $firstLine = strtok($csv, "\r\n");
        if ($firstLine === false) {
            return false;
        }
        $headers = array_map(
            static fn(?string $h): string => trim((string) ($h ?? '')),
            str_getcsv($firstLine, ',', '"', '\\'),
        );
        return array_map('strtolower', $headers) === self::REQUIRED_HEADERS;
    }
}
```

### RouteRegistrar (Auszug)

```php
private function handleCreateImport(ServerRequestInterface $request): ResponseInterface
{
    $body = (array) ($request->getParsedBody() ?? []);

    if (!isset($body['csv']) || !is_string($body['csv'])) {
        throw new ValidationException([new ValidationError('csv', 'csv is required', 'required')]);
    }

    $csv = $body['csv'];
    if (trim($csv) === '') {
        throw new ValidationException([new ValidationError('csv', 'csv must not be empty', 'required')]);
    }

    if (!$this->importer->validateHeader($csv)) {
        throw new ValidationException([
            new ValidationError('csv', 'CSV must have header row: name,email,age', 'invalid_format'),
        ]);
    }

    $parsed = $this->importer->parse($csv);
    $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');

    $jobId = $this->repo->createJob(
        $filename,
        count($parsed['rows']) + count($parsed['errors']),
        count($parsed['rows']),
        count($parsed['errors']),
        $parsed['errors'],
        $now,
    );

    foreach ($parsed['rows'] as $row) {
        $this->repo->insertRecord($jobId, $row['name'], $row['email'], $row['age'], $now);
    }

    return $this->json->create($this->formatJob($this->repo->findJob($jobId)), 201);
}
```

---

## Design-Kernpunkte

### PHP 8.4: str_getcsv() $escape-Pflicht

In PHP 8.4 wurde der `$escape`-Parameter von `str_getcsv()` obligatorisch (Übergangszeitraum für Standardwertänderung).
Ohne explizite Angabe tritt eine Deprecation-Warnung auf.

```php
// Falsch: PHP 8.4 Deprecation
$fields = str_getcsv($line);

// Richtig: $escape explizit angeben (RFC 4180 kompatibel)
$fields = str_getcsv($line, ',', '"', '\\');
```

Außerdem kann `str_getcsv()` bei leeren Feldern `null` zurückgeben. In PHP 8.4 ist `trim(null)` ebenfalls deprecated, daher explizit behandeln:

```php
$fields = array_map(static fn(?string $f): string => trim((string) ($f ?? '')), $fields);
```

### Teilerfolg-Muster

Bei Massenimporten ist es praktisch, **nur gültige Zeilen zu importieren und Fehler ungültiger Zeilen zu sammeln**, statt "alles oder nichts":

```php
$parsed = $this->importer->parse($csv);
// $parsed['rows'] = Liste gültiger Zeilen → INSERT
// $parsed['errors'] = [{row: 3, value: "bad@", error: "invalid email format"}, ...]
```

In der Antwort `imported_rows` / `failed_rows` / `errors` zurückgeben:

```json
{
  "imported_rows": 4,
  "failed_rows": 1,
  "errors": [{"row": 3, "value": "bad-email", "error": "invalid email format"}]
}
```

### Erkennung doppelter E-Mails im Batch

Selbst wenn dieselbe E-Mail mehrfach in derselben CSV-Datei vorkommt, sollte nicht auf DB-Constraints vertraut werden, sondern der Importer verwendet eine Hash-Map zur Vorab-Erkennung:

```php
$seenEmails = [];
// ...
if (isset($seenEmails[$email])) {
    $rowErrors[] = 'duplicate email in import batch';
}
// ...
$seenEmails[$email] = true;
```

Das Abfangen von DB-Constraint-Fehlern macht unklar, ob die Zeile tatsächlich eingefügt wurde und liefert unklare Fehlermeldungen. Die Vorab-Erkennung ist expliziter und UX-freundlicher.

### CRLF-Unterstützung

Windows-generierte CSV-Dateien verwenden `\r\n` als Zeilenumbruch. Mit `preg_split('/\r\n|\r|\n/', ...)` einheitlich verarbeiten:

```php
$lines = preg_split('/\r\n|\r|\n/', trim($csv));
```

### errors-Feld JSON-Persistenz

`errors` wird als JSON-String in einer TEXT-Spalte der DB gespeichert und beim Abrufen dekodiert:

```php
// Speichern
json_encode($errors)

// Abrufen und formatieren
$errors = json_decode((string) $job['errors'], true) ?? [];
```

SQLite hat keinen JSON-Typ, daher wird TEXT als Ersatz verwendet. MySQL verhält sich ähnlich (JSON-Typ kann verwendet werden, aber für Kompatibilität wurde TEXT gewählt).

---

## Beispielantworten

### POST /imports (Teilerfolg)

```json
{
  "id": 1,
  "filename": "users.csv",
  "status": "completed",
  "total_rows": 3,
  "imported_rows": 2,
  "failed_rows": 1,
  "errors": [
    {"row": 3, "value": "bad-email", "error": "invalid email format"}
  ],
  "created_at": "2026-01-01T00:00:00Z",
  "completed_at": "2026-01-01T00:00:00Z"
}
```

### GET /imports/{id} (mit Datensätzen)

```json
{
  "id": 1,
  "filename": "users.csv",
  "status": "completed",
  "total_rows": 2,
  "imported_rows": 2,
  "failed_rows": 0,
  "errors": [],
  "records": [
    {"id": 1, "name": "Alice", "email": "alice@example.com", "age": 30, "created_at": "..."},
    {"id": 2, "name": "Bob",   "email": "bob@example.com",   "age": null, "created_at": "..."}
  ]
}
```

---

## MySQL-Integrationstests

In der MySQL-Umgebung die Umgebungsvariable `MYSQL_HOST` setzen, um Integrationstests auszuführen:

```bash
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3306 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass phpunit
```

In Integrationstests prüfen:
- 100-Zeilen-Massenimport wird korrekt vollständig INSERT eingefügt
- Teilerfolg: Nur gültige Zeilen werden in der DB gespeichert
- Doppelte E-Mails im Batch werden erkannt und ausgeschlossen

---

## Referenzimplementierung

`../NENE2-FT/importlog/` — FT158 Field Trial (22 Tests + 5 MySQL-Integrationstests)
