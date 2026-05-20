# Field Trial 70 — Content Versioning with Optimistic Locking (versionlog)

**Date**: 2026-05-20
**NENE2 version**: 1.5.22
**Project**: `/home/xi/docker/NENE2-FT/versionlog/`
**Theme**: Document content versioning with append-only version history, restore to previous version, and optimistic locking for concurrent-edit conflict detection.

---

## What was built

A versioned document API where every content change creates an immutable version entry. Clients must supply the current version number to apply edits, preventing lost updates.

### Domain

- `Document` — has `title`, `current_version` (integer), and a lazy-loaded list of `DocumentVersion` objects
- `DocumentVersion` — immutable snapshot: `version` (integer sequence), `content`, `author`, `created_at`
- `Document::latestContent()` — walks `versions` to find the entry matching `current_version`
- `Document::toArray(includeVersions: true)` — named argument for optional version history embedding

### Schema

```sql
CREATE TABLE IF NOT EXISTS documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    current_version INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS document_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL REFERENCES documents(id),
    version INTEGER NOT NULL,
    content TEXT NOT NULL,
    author TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(document_id, version)
);
```

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/documents` | Create document (title) |
| GET | `/documents` | List all documents (current content only) |
| GET | `/documents/{id}` | Get document with current content |
| GET | `/documents/{id}/versions` | Get document with full version history |
| POST | `/documents/{id}/versions` | Append new version (content, author, expected_version) |
| POST | `/documents/{id}/restore/{version}` | Restore to a past version (creates new version entry) |

### Key design decisions

**Optimistic locking**: `POST /documents/{id}/versions` requires `expected_version` in the body. The repository compares it against `documents.current_version`; mismatch returns `null` → 409 Conflict. This prevents silent lost updates without DB-level locking.

**Restore is append-only**: `POST /documents/{id}/restore/{version}` does not mutate history. It creates a new version entry with the old content (`version = current + 1`). The audit trail remains complete.

**Named argument for response shape**: `toArray(includeVersions: true)` uses PHP 8 named arguments to toggle the verbose shape (with full version array). Default shape omits `versions` to keep list responses lean.

**Two path parameters on restore route**: `POST /documents/{id}/restore/{version}` — NENE2 v1.5.22 handles two path params cleanly.

**New document starts at version 0**: `current_version = 0` signals "no content yet". The first `POST /versions` with `expected_version: 0` creates version 1. This keeps the version numbering consistent and avoids special-casing.

### Test results

```
OK (17 tests, 35 assertions)
PHPStan level 8: No errors
PHP-CS-Fixer: 0 files to fix
```

---

## Friction log

No frictions encountered. NENE2 v1.5.22 handled all versioning patterns cleanly:

- Two path parameters on one route (`{id}/restore/{version}`): ✅
- `toArray()` with named boolean argument: ✅ (PHP 8.0 named args, no framework involvement)
- Optimistic locking (compare-and-swap in repo layer): ✅
- Append-only restore producing new version entry: ✅

---

## Summary

Content versioning with optimistic locking works well with NENE2 v1.5.22. No framework changes needed. The compare-and-swap logic lives cleanly in the repository layer, and the append-only version history is a natural fit for the SQLite UNIQUE(document_id, version) constraint.
