# Guide d'implémentation de l'API d'import CSV en masse

## Vue d'ensemble

Ce guide explique comment implémenter une API d'import CSV en masse avec NENE2.
Propose la validation ligne par ligne, le succès partiel, la collecte d'erreurs et la gestion de l'historique d'import en tant qu'API REST.

---

## Schéma DB

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

## Conception des endpoints

| Méthode | Chemin | Description |
|---------|--------|-------------|
| POST | `/imports` | Importer un CSV (traitement synchrone, succès partiel supporté) |
| GET | `/imports` | Liste des jobs d'import |
| GET | `/imports/{importId}` | Résultat d'import + enregistrements |

### Format de la requête

```json
POST /imports
{
  "csv": "name,email,age\nAlice,alice@example.com,30\nBob,bob@example.com,25",
  "filename": "users.csv"
}
```

Le CSV est envoyé comme chaîne dans le champ `csv` du corps JSON. Cela facilite les tests dans un flux API JSON standard.

---

## Implémentation

### CsvImporter (parseur pur)

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
            // PHP 8.4 : le paramètre $escape doit être explicite sinon deprecation
            $fields = str_getcsv($line, ',', '"', '\\');
            $fields = array_map(static fn(?string $f): string => trim((string) ($f ?? '')), $fields);

            if ($i === 0) {
                continue; // ignorer l'en-tête
            }
            // ... validation et collecte
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

### RouteRegistrar (extrait)

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

## Points clés de conception

### PHP 8.4 : obligation du paramètre $escape dans str_getcsv()

En PHP 8.4, le paramètre `$escape` de `str_getcsv()` est devenu obligatoire (période de transition vers un changement de valeur par défaut). Ne pas le spécifier génère une deprecation.

```php
// Incorrect : deprecation PHP 8.4
$fields = str_getcsv($line);

// Correct : spécifier $escape explicitement (compatible RFC 4180)
$fields = str_getcsv($line, ',', '"', '\\');
```

De plus, `str_getcsv()` peut retourner `null` pour les champs vides. En PHP 8.4, `trim(null)` génère aussi une deprecation — le traiter explicitement :

```php
$fields = array_map(static fn(?string $f): string => trim((string) ($f ?? '')), $fields);
```

### Pattern de succès partiel

Pour les imports en masse, il est pratique d'**importer uniquement les lignes valides + collecter les erreurs des lignes invalides** plutôt que de tout réussir ou tout échouer :

```php
$parsed = $this->importer->parse($csv);
// $parsed['rows'] = liste de lignes valides → INSERT
// $parsed['errors'] = [{row: 3, value: "bad@", error: "invalid email format"}, ...]
```

Retourner `imported_rows` / `failed_rows` / `errors` dans la réponse :

```json
{
  "imported_rows": 4,
  "failed_rows": 1,
  "errors": [{"row": 3, "value": "bad-email", "error": "invalid email format"}]
}
```

### Détection des emails en doublon dans le lot

Même si le même email apparaît sur plusieurs lignes du même fichier CSV, détecter en amont avec une hashmap côté importeur plutôt que de dépendre de la contrainte DB :

```php
$seenEmails = [];
// ...
if (isset($seenEmails[$email])) {
    $rowErrors[] = 'duplicate email in import batch';
}
// ...
$seenEmails[$email] = true;
```

Capturer les erreurs de contrainte DB rend ambigu si la ligne a été insérée ou non, et les messages d'erreur sont opaques. La détection en amont est plus explicite et offre une meilleure UX.

### Gestion des sauts de ligne CRLF

Les CSV générés sous Windows utilisent `\r\n` comme saut de ligne. Traitement unifié avec `preg_split('/\r\n|\r|\n/', ...)` :

```php
$lines = preg_split('/\r\n|\r|\n/', trim($csv));
```

### Persistance JSON du champ errors

`errors` est stocké comme chaîne JSON dans une colonne TEXT de la DB et décodé à la récupération :

```php
// Sauvegarde
json_encode($errors)

// Récupération et formatage
$errors = json_decode((string) $job['errors'], true) ?? [];
```

SQLite n'a pas de type JSON donc on utilise TEXT. MySQL aussi (on pourrait utiliser le type JSON pour de meilleures performances mais TEXT maintient la compatibilité).

---

## Exemples de réponse

### POST /imports (succès partiel)

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

### GET /imports/{id} (avec enregistrements)

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

## Tests d'intégration MySQL

Dans un environnement MySQL, définir la variable d'environnement `MYSQL_HOST` pour exécuter les tests d'intégration :

```bash
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3306 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass phpunit
```

Ce que les tests d'intégration vérifient :
- L'import en masse de 100 lignes insère correctement tous les enregistrements
- En cas de succès partiel, seules les lignes valides sont sauvegardées en DB
- Les emails en doublon dans le lot sont détectés et exclus

---

## Implémentation de référence

`../NENE2-FT/importlog/` — FT158 field trial (22 tests + 5 tests d'intégration MySQL)
