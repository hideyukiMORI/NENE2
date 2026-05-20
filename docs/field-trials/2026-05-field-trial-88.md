# Field Trial 88 — Contact List / Address Book (contactlog)

**Date:** 2026-05-20
**Project:** `/home/xi/docker/NENE2-FT/contactlog/`
**NENE2 version:** 1.5.23
**Theme:** Multi-owner contact list with group membership (M:N), name/email search, owner isolation

---

## What was built

A personal contact list API where each owner has their own contacts and groups. Contacts can belong to multiple groups (M:N via join table). Search supports free-text query across name and email fields, and filtering by group membership.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/owners/{ownerId}/contacts` | Create a contact |
| GET | `/owners/{ownerId}/contacts` | List/search contacts (`?q=`, `?group_id=`) |
| GET | `/owners/{ownerId}/contacts/{id}` | Get a contact with groups |
| PUT | `/owners/{ownerId}/contacts/{id}` | Full update a contact |
| DELETE | `/owners/{ownerId}/contacts/{id}` | Delete contact and memberships |
| POST | `/owners/{ownerId}/groups` | Create a group (unique per owner) |
| PUT | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}` | Add to group (idempotent) |
| DELETE | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}` | Remove from group |

### Schema

```sql
CREATE TABLE IF NOT EXISTS contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT, owner_id TEXT NOT NULL,
    name TEXT NOT NULL, email TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT, owner_id TEXT NOT NULL, name TEXT NOT NULL,
    created_at TEXT NOT NULL, UNIQUE(owner_id, name)
);
CREATE TABLE IF NOT EXISTS contact_groups (
    contact_id INTEGER NOT NULL, group_id INTEGER NOT NULL, PRIMARY KEY (contact_id, group_id)
);
```

---

## Frictions found

### 1. SQLite `LIKE` is case-insensitive for ASCII (affects test design)

**Severity:** Low (test design friction)

SQLite's `LIKE` operator is case-insensitive for ASCII characters by default. The search query `c.email LIKE '%Bob%'` matches `bob@example.com` because `b` and `B` are treated as equal.

**Impact on tests:** A test that creates two contacts sharing the same email address (`bob@example.com`) and searches for `q=Bob` will return both contacts — the name search hits "Bob Smith" and the email search hits `bob@example.com` in "Carol Jones"'s record.

**Fix applied in test:** Each test contact uses a unique email address to avoid cross-contact LIKE matches.

**Framework implication:** The LIKE case-insensitivity is SQLite-specific behavior. On MySQL (`LOWER()` required for explicit case-insensitive search) and PostgreSQL (`ILIKE` or `LOWER()`) the behavior differs. Documented as a test design caution for multi-adapter projects.

---

### 2. Missing `@return array<string, mixed>|null` PHPDoc for mixed-return methods

**Severity:** Low (recurring — same as FT84 F-1)

`createGroup(): ?array` triggers PHPStan level 8 `missingType.iterableValue`. Fix: add `@return array<string, mixed>|null`. This is the same issue as FT84's `listWaiting(): array` — any method returning an untyped `array` requires explicit PHPDoc for PHPStan level 8 compliance.

---

### 3. Group names create a composite unique key per owner

**Severity:** Low (design note)

`UNIQUE(owner_id, name)` allows the same group name across different owners. Duplicate names within one owner return 409 via `DatabaseConstraintException`. This is the correct constraint for a per-owner address book.

---

### 4. `EXISTS` subquery for group filter avoids JOIN duplication

**Severity:** Low (positive finding)

Filtering contacts by group uses:
```sql
EXISTS (SELECT 1 FROM contact_groups cg WHERE cg.contact_id = c.id AND cg.group_id = ?)
```

This avoids a JOIN that would duplicate rows if a contact is in multiple groups. The EXISTS subquery is semantically clean and performs well on indexed tables.

---

## Results

| Check | Result |
|-------|--------|
| PHPUnit tests | 17 tests, 29 assertions — OK (1 test adjusted for LIKE case sensitivity) |
| PHPStan level 8 | 1 error (missing `@return` annotation) → fixed → No errors |
| PHP-CS-Fixer | 0 files to fix |

---

## Notes

- Groups are fetched per-contact with a JOIN query. For large contact lists, this creates N+1 queries. The pattern is acceptable for personal address books; production scale would batch-load groups.
- Delete cascades membership rows explicitly before deleting the contact (no FK cascade in SQLite without PRAGMA).
- Add-to-group is idempotent — catches `DatabaseConstraintException` on the composite PK.
