# How-to: Dokumentversionierungs-API

> **FT-Referenz**: FT239 (`NENE2-FT/doclog`) — Dokumentversionierungs-API

Demonstriert ein Append-only-Dokumentversionierungssystem, bei dem die aktuelle Version mit einem `is_current`-Flag verfolgt wird, ein Revert eine neue Version erstellt (nicht-destruktiv) und alle mehrstufigen Schreibvorgänge über `DatabaseTransactionManagerInterface` in Transaktionen eingeschlossen sind.

---

## Routen

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST` | `/documents` | Ein Dokument mit seiner ersten Version erstellen |
| `GET`  | `/documents` | Dokumente auflisten (paginiert) mit aktueller Version |
| `GET`  | `/documents/{id}` | Ein Dokument mit seiner aktuellen Version abrufen |
| `GET`  | `/documents/{id}/versions` | Versionshistorie auflisten (paginiert) |
| `POST` | `/documents/{id}/versions` | Eine neue Version hinzufügen |
| `POST` | `/documents/{id}/revert/{version}` | Auf eine bestimmte Versionsnummer zurücksetzen |

Statische Sub-Routen (`/documents/{id}/versions`) werden vor der parametrisierten Route `/documents/{id}` registriert, um korrektes Dispatching sicherzustellen.

---

## Schema: `is_current`-Flag-Muster

```sql
CREATE TABLE IF NOT EXISTS documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS document_versions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    content     TEXT    NOT NULL,
    version_num INTEGER NOT NULL,
    is_current  INTEGER NOT NULL DEFAULT 0 CHECK(is_current IN (0, 1)),
    created_at  TEXT    NOT NULL,
    UNIQUE(document_id, version_num)
);
CREATE INDEX IF NOT EXISTS idx_versions_document ON document_versions(document_id);
```

`is_current` ist ein boolesches Flag (0/1), gespeichert als INTEGER, eingeschränkt durch `CHECK`. Höchstens eine Zeile pro Dokument sollte `is_current = 1` haben. `UNIQUE(document_id, version_num)` verhindert doppelte Versionsnummern für dasselbe Dokument.

**Vergleich mit `current_version` als Integer**: Der `is_current`-Flag-Ansatz vermeidet die Notwendigkeit, eine Spalte in der übergeordneten `documents`-Tabelle zu aktualisieren, wenn die Version wechselt. Das Flag wird direkt in der `document_versions`-Tabelle in derselben Transaktion umgeschaltet, die die neue Version einfügt.

---

## Aktuelle Version mit JOIN abrufen

Die List- und Show-Abfragen verwenden einen `LEFT JOIN` gefiltert auf `is_current = 1`, um die aktuelle Version in einer einzigen Abfrage abzurufen:

```php
$row = $this->executor->fetchOne(
    'SELECT d.*, dv.id AS vid, dv.content, dv.version_num, dv.is_current,
            dv.created_at AS version_created_at
     FROM documents d
     LEFT JOIN document_versions dv ON dv.document_id = d.id AND dv.is_current = 1
     WHERE d.id = ?',
    [$id],
);
```

`LEFT JOIN ... AND dv.is_current = 1` — die Join-Bedingung filtert auf die aktuelle Version. Ein Dokument ohne Versionen gibt eine `NULL`-Join-Zeile zurück, die als `currentVersion: null` hydriert wird.

---

## Version hinzufügen: Drei-Schritt-Transaktion

Das Hinzufügen einer Version erfordert drei aufeinanderfolgende Operationen, eingeschlossen in eine Transaktion:

```php
public function addVersion(int $documentId, string $content, string $now): Document
{
    return $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($documentId, $content, $now): Document {
        // Schritt 1: Nächste Versionsnummer berechnen
        $maxRow     = $tx->fetchOne('SELECT MAX(version_num) AS max_ver FROM document_versions WHERE document_id = ?', [$documentId]);
        $nextVerNum = ((int) ($maxRow['max_ver'] ?? 0)) + 1;

        // Schritt 2: Aktuelle Version deaktivieren
        $tx->execute('UPDATE document_versions SET is_current = 0 WHERE document_id = ? AND is_current = 1', [$documentId]);

        // Schritt 3: Neue Version als aktuell einfügen
        $versionId = $tx->insert(
            'INSERT INTO document_versions (document_id, content, version_num, is_current, created_at) VALUES (?, ?, ?, 1, ?)',
            [$documentId, $content, $nextVerNum, $now],
        );

        // Schritt 4: updated_at des Dokuments aktualisieren
        $tx->execute('UPDATE documents SET updated_at = ? WHERE id = ?', [$now, $documentId]);
        // ...
    });
}
```

`DatabaseTransactionManagerInterface::transactional()` schließt den Closure in eine Transaktion ein. Wenn ein Schritt eine Exception wirft, wird die Transaktion zurückgerollt. Der `$tx`-Parameter ist der auf die Transaktion begrenzte Executor — keine separate Verbindung erforderlich.

---

## Nicht-destruktives Zurücksetzen: Als neue Version kopieren

Reverts ändern keine bestehende Historie — sie erstellen eine neue Version mit dem Inhalt der Zielversion:

```php
public function revertToVersion(int $documentId, int $versionNum, string $now): Document
{
    return $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($documentId, $versionNum, $now): Document {
        $targetRow = $tx->fetchOne(
            'SELECT * FROM document_versions WHERE document_id = ? AND version_num = ?',
            [$documentId, $versionNum],
        );

        if ($targetRow === null) {
            throw new VersionNotFoundException($documentId, $versionNum);
        }

        // Nächste Versionsnummer für die Revert-Kopie berechnen
        $maxRow     = $tx->fetchOne('SELECT MAX(version_num) AS max_ver FROM document_versions WHERE document_id = ?', [$documentId]);
        $nextVerNum = ((int) ($maxRow['max_ver'] ?? 0)) + 1;

        // Aktuelle Version deaktivieren
        $tx->execute('UPDATE document_versions SET is_current = 0 WHERE document_id = ? AND is_current = 1', [$documentId]);

        // Kopie des Zielinhalts als neue aktuelle Version einfügen
        $newVersionId = $tx->insert(
            'INSERT INTO document_versions (document_id, content, version_num, is_current, created_at) VALUES (?, ?, ?, 1, ?)',
            [$documentId, (string) $targetRow['content'], $nextVerNum, $now],
        );
        // ...
    });
}
```

Wenn ein Dokument bei Version 5 ist und auf Version 2 zurückgesetzt wird, wird Version 6 mit dem Inhalt von Version 2 erstellt. Die Historie ist:
```
v1 → v2 → v3 → v4 → v5 → v6 (Kopie von v2)
```

Dieser Ansatz bewahrt den vollständigen Audit-Trail — der Revert selbst ist in der Historie als neuer Eintrag sichtbar. Es ist unmöglich, Historie zu "verlieren".

---

## VersionNotFoundException mit strukturiertem Kontext

`VersionNotFoundException` enthält sowohl die Dokument-ID als auch die Versionsnummer:

```php
final class VersionNotFoundException extends \RuntimeException
{
    public function __construct(int $documentId, int $versionNum)
    {
        parent::__construct("Version {$versionNum} not found for document {$documentId}.");
    }
}
```

Die Exception wird innerhalb des Transaktions-Closures geworfen. Der Exception-Handler bildet sie auf eine `404 Not Found`-Antwort ab. Da die Exception vor Schreiboperationen im Revert geworfen wird, wird die Transaktion sauber zurückgerollt.

---

## NENE2-Builtins: PaginationQueryParser und PaginationResponse

List-Endpunkte verwenden NENE2's Paginierungs-Helfer:

```php
private function listDocuments(ServerRequestInterface $request): ResponseInterface
{
    $pagination = PaginationQueryParser::parse($request);
    $items      = $this->repository->findAll($pagination->limit, $pagination->offset);
    $total      = $this->repository->countAll();

    $response = new PaginationResponse(
        items: array_map($this->serializeDocument(...), $items),
        limit: $pagination->limit,
        offset: $pagination->offset,
        total: $total,
    );

    return $this->json->create($response->toArray());
}
```

`PaginationQueryParser::parse()` liest `?limit=` und `?offset=` aus den Query-Parametern mit sicheren Standardwerten und Grenzen. `PaginationResponse::toArray()` produziert einen konsistenten Envelope: `{ items, total, limit, offset }`.

---

## NENE2-Builtins: ValidationException und ValidationError

Eingabevalidierung verwendet NENE2's strukturierte Validierungs-Helfer:

```php
$errors = [];
if (!isset($body['title']) || !is_string($body['title']) || trim($body['title']) === '') {
    $errors[] = new ValidationError('title', 'title is required.', 'required');
}
if (!isset($body['content']) || !is_string($body['content'])) {
    $errors[] = new ValidationError('content', 'content is required.', 'required');
}
if ($errors !== []) {
    throw new ValidationException($errors);
}
```

`ValidationException` wird von NENE2's Error-Handler abgefangen und in eine `422 Unprocessable Entity` Problem Details-Antwort mit einem strukturierten `errors`-Array umgewandelt — identisch mit dem Aufruf von `ProblemDetailsResponseFactory::create()` mit `errors`-Erweiterung, aber über den Exception-basierten Pfad.

---

## Verwandte Anleitungen

- [`content-versioning.md`](content-versioning.md) — Integer-basiertes current_version-Muster
- [`audit-trail.md`](audit-trail.md) — Append-only-Historienmuster
- [`transactions.md`](transactions.md) — DatabaseTransactionManagerInterface-Muster
- [`use-transactions.md`](use-transactions.md) — Mehrstufige Schreibvorgänge einschließen
