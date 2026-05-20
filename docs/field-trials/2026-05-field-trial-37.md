# Field Trial 37 — Document Versioning API (doclog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/doclog/`
**NENE2 version**: 1.5.17
**Theme**: Document versioning with `document_versions` table, transaction spanning INSERT+UPDATE, revert-to-version pattern, JOIN with current version, version number scoped per document

## Overview

Built a document versioning API. Each document has a `document_versions` table with an `is_current` flag. Adding a new version involves a transaction: deactivate the current version (`UPDATE … SET is_current = 0`), insert the new version (`INSERT … is_current = 1`), update the document's `updated_at`. Revert creates a new version whose content is a copy of the target version — preserving the full history rather than discarding intermediate versions.

## Endpoints Implemented

- `POST /documents` — create document with initial version (title, content)
- `GET /documents` — list all documents with current version, paginated
- `GET /documents/{id}` — show document with current version
- `POST /documents/{id}/versions` — add new version (content); transaction bump
- `GET /documents/{id}/versions` — full version history, paginated, most recent first
- `POST /documents/{id}/revert/{version}` — revert to version N (creates new version N+1 with same content)

## Test Results

20 tests, 42 assertions — all pass after 1 PHPStan fix.

---

## Frictions Found

### Friction 1 — PHPStan level 8: `isset()` + `!== null` redundant check (recurring) [LOW]

**Symptom**: Same as FT34. In `hydrateDocument()`:

```php
$version = isset($row['vid']) && $row['vid'] !== null
    ? new DocumentVersion(...)
    : null;
```

PHPStan error: `Strict comparison using !== between mixed and null will always evaluate to true. Type null has already been eliminated from mixed.`

**Fix**: Remove the redundant check:

```php
$version = isset($row['vid'])
    ? new DocumentVersion(...)
    : null;
```

**NENE2 impact**: Already documented in FT34 and listed in `add-domain-exception-handler.md` Common Mistakes. This is a PHP/PHPStan pattern, not a NENE2 issue. No new documentation needed.

---

## Patterns Validated

### Transaction spanning multiple statements

The `transactional()` callback receives a `$tx` executor already bound to the PDO transaction. No `new self($tx, ...)` required — use `$tx` directly:

```php
$this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use (...): Document {
    $tx->execute('UPDATE document_versions SET is_current = 0 WHERE document_id = ? AND is_current = 1', [$documentId]);
    $versionId = $tx->insert('INSERT INTO document_versions (...) VALUES (?, ?, ?, 1, ?)', [...]);
    $tx->execute('UPDATE documents SET updated_at = ? WHERE id = ?', [$now, $documentId]);
    // ...
});
```

### JOIN with is_current flag for single-query document fetch

```sql
SELECT d.*, dv.id AS vid, dv.content, dv.version_num, dv.is_current, dv.created_at AS version_created_at
FROM documents d
LEFT JOIN document_versions dv ON dv.document_id = d.id AND dv.is_current = 1
WHERE d.id = ?
```

`LEFT JOIN` (not `INNER JOIN`) ensures documents without any version (edge case) still return a row — the version fields are NULL and `hydrateDocument()` handles this with `isset($row['vid'])`.

### Version-scoped auto-increment using MAX

```sql
SELECT MAX(version_num) AS max_ver FROM document_versions WHERE document_id = ?
```

SQLite's `AUTOINCREMENT` is global. To get a per-document version number, query `MAX(version_num)` within the transaction and add 1. This is safe because the transaction serialises access.

### Revert-as-copy pattern

Revert does not roll back the `is_current` pointer to an old version — it creates a new version whose content is a copy of the target. This preserves the full audit trail:

```
v1 (created) → v2 (edited) → v3 (revert of v1, content = v1's content)
```

History always grows monotonically; no version is ever deleted.

### Route ordering for nested sub-routes

Routes with shared `{id}` prefix require static sub-routes to come before the parameterized catch-all:

```php
$router->get('/documents/{id}/versions', $this->listVersions(...));
$router->post('/documents/{id}/versions', $this->addVersion(...));
$router->post('/documents/{id}/revert/{version}', $this->revertToVersion(...));
$router->get('/documents/{id}', $this->showDocument(...)); // catch-all last
```

`/documents/{id}/versions` and `/documents/{id}/revert/{version}` are not ambiguous with `/documents/{id}` because they have additional path segments — registration order doesn't matter here. But `GET /documents/{id}` must still come after `GET /documents` (the static list route), which it does.

---

## NENE2 Changes Required

None — zero new frictions, and the one PHPStan fix is a known PHP pattern already documented.

No version bump needed.
