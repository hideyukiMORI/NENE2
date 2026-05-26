# How-to: Document Versioning API

> **FT reference**: FT239 (`NENE2-FT/doclog`) — Document Versioning API

Demonstrates an append-only document versioning system where the current version is
tracked with an `is_current` flag, revert creates a new version (non-destructive),
and all multi-step writes are wrapped in transactions via `DatabaseTransactionManagerInterface`.

---

## Routes

| Method | Path                                      | Description                                          |
|--------|-------------------------------------------|------------------------------------------------------|
| `POST` | `/documents`                              | Create a document with its first version             |
| `GET`  | `/documents`                              | List documents (paginated) with current version      |
| `GET`  | `/documents/{id}`                         | Get a document with its current version              |
| `GET`  | `/documents/{id}/versions`                | List version history (paginated)                     |
| `POST` | `/documents/{id}/versions`                | Add a new version                                    |
| `POST` | `/documents/{id}/revert/{version}`        | Revert to a specific version number                  |

Static sub-routes (`/documents/{id}/versions`) are registered before the parameterised
`/documents/{id}` route to ensure correct dispatch.

---

## Schema: `is_current` flag pattern

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

`is_current` is a boolean flag (0/1) stored as INTEGER, constrained by `CHECK`. At
most one row per document should have `is_current = 1`. `UNIQUE(document_id, version_num)`
prevents duplicate version numbers for the same document.

**Comparison with `current_version` integer**: the `is_current` flag approach avoids
the need to update a column on the parent `documents` table every time the version
changes. The flag is toggled on the `document_versions` table directly in the same
transaction that inserts the new version.

---

## Fetching the current version with JOIN

The list and show queries use a `LEFT JOIN` filtered on `is_current = 1` to retrieve
the current version in a single query:

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

`LEFT JOIN ... AND dv.is_current = 1` — the join condition filters to the current
version only. A document with no versions returns a `NULL` join row, hydrated as
`currentVersion: null`.

---

## Adding a version: three-step transaction

Adding a version requires three operations in sequence, wrapped in a transaction:

```php
public function addVersion(int $documentId, string $content, string $now): Document
{
    return $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($documentId, $content, $now): Document {
        // Step 1: Compute next version number
        $maxRow     = $tx->fetchOne('SELECT MAX(version_num) AS max_ver FROM document_versions WHERE document_id = ?', [$documentId]);
        $nextVerNum = ((int) ($maxRow['max_ver'] ?? 0)) + 1;

        // Step 2: Deactivate the current version
        $tx->execute('UPDATE document_versions SET is_current = 0 WHERE document_id = ? AND is_current = 1', [$documentId]);

        // Step 3: Insert the new version as current
        $versionId = $tx->insert(
            'INSERT INTO document_versions (document_id, content, version_num, is_current, created_at) VALUES (?, ?, ?, 1, ?)',
            [$documentId, $content, $nextVerNum, $now],
        );

        // Step 4: Update document's updated_at
        $tx->execute('UPDATE documents SET updated_at = ? WHERE id = ?', [$now, $documentId]);
        // ...
    });
}
```

`DatabaseTransactionManagerInterface::transactional()` wraps the closure in a transaction.
If any step throws, the transaction is rolled back. The `$tx` parameter is the executor
scoped to the transaction — no separate connection needed.

---

## Non-destructive revert: copy as new version

Reverts do not change existing history — they create a new version containing the
content of the target version:

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

        // Compute next version number for the revert copy
        $maxRow     = $tx->fetchOne('SELECT MAX(version_num) AS max_ver FROM document_versions WHERE document_id = ?', [$documentId]);
        $nextVerNum = ((int) ($maxRow['max_ver'] ?? 0)) + 1;

        // Deactivate current version
        $tx->execute('UPDATE document_versions SET is_current = 0 WHERE document_id = ? AND is_current = 1', [$documentId]);

        // Insert a copy of the target content as the new current version
        $newVersionId = $tx->insert(
            'INSERT INTO document_versions (document_id, content, version_num, is_current, created_at) VALUES (?, ?, ?, 1, ?)',
            [$documentId, (string) $targetRow['content'], $nextVerNum, $now],
        );
        // ...
    });
}
```

If a document is at version 5 and reverted to version 2, version 6 is created with
version 2's content. The history is:
```
v1 → v2 → v3 → v4 → v5 → v6 (copy of v2)
```

This approach preserves the full audit trail — the revert itself is visible in the
history as a new entry. It is impossible to "lose" history.

---

## VersionNotFoundException with structured context

`VersionNotFoundException` carries both the document ID and version number:

```php
final class VersionNotFoundException extends \RuntimeException
{
    public function __construct(int $documentId, int $versionNum)
    {
        parent::__construct("Version {$versionNum} not found for document {$documentId}.");
    }
}
```

The exception is thrown inside the transaction closure. The exception handler maps it
to a `404 Not Found` response. Because the exception is thrown before any write
operations in the revert, the transaction is rolled back cleanly.

---

## NENE2 built-ins: PaginationQueryParser and PaginationResponse

List endpoints use NENE2's pagination helpers:

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

`PaginationQueryParser::parse()` reads `?limit=` and `?offset=` from query params with
safe defaults and bounds. `PaginationResponse::toArray()` produces a consistent
envelope: `{ items, total, limit, offset }`.

---

## NENE2 built-ins: ValidationException and ValidationError

Input validation uses NENE2's structured validation helpers:

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

`ValidationException` is caught by NENE2's error handler and converted to a
`422 Unprocessable Entity` Problem Details response with a structured `errors` array —
identical to calling `ProblemDetailsResponseFactory::create()` with `errors` extension,
but via the exception-based path.

---

## Related howtos

- [`content-versioning.md`](content-versioning.md) — integer-based current_version pattern
- [`audit-trail.md`](audit-trail.md) — append-only history patterns
- [`transactions.md`](transactions.md) — DatabaseTransactionManagerInterface patterns
- [`use-transactions.md`](use-transactions.md) — wrapping multi-write operations
