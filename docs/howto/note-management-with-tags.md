---
title: "How-to: Note Management with Tags"
category: product
tags: [notes, tags, full-text-search, ownership, crud]
difficulty: intermediate
related: [note-management-ownership, tagging-system]
---

# How-to: Note Management with Tags

## Overview

This guide covers building a tagged note management API with NENE2. Features include per-user isolation, tag-based filtering, full-text keyword search, and ownership-enforced CRUD.

**Reference implementation**: `../NENE2-FT/notelog/`

---

## Schema Design

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS note_tags (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    note_id INTEGER NOT NULL,
    tag     TEXT    NOT NULL,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    UNIQUE (note_id, tag)
);
```

`ON DELETE CASCADE` removes tags automatically when a note is deleted.

---

## Route Table

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/notes` | User | Create a note |
| `GET` | `/notes` | User | List own notes (optional `?tag=` or `?q=`) |
| `GET` | `/notes/{id}` | User | Get one note |
| `PUT` | `/notes/{id}` | User | Update note fields |
| `DELETE` | `/notes/{id}` | User | Delete a note |

---

## Tag Filtering

Filter by tag with `JOIN`:

```sql
SELECT n.* FROM notes n
JOIN note_tags t ON t.note_id = n.id
WHERE n.user_id = :uid AND t.tag = :tag
ORDER BY n.id DESC
```

---

## Keyword Search

Full-text search across title and body using `LIKE`:

```sql
SELECT * FROM notes
WHERE user_id = :uid AND (title LIKE :kw OR body LIKE :kw)
ORDER BY id DESC
```

The `:kw` placeholder is `'%' . $keyword . '%'`. Parameterized queries prevent SQL injection.

---

## Tag Parsing

Tags must be arrays of strings; normalize to lowercase:

```php
private function parseTags(mixed $raw): ?array
{
    if (!is_array($raw)) return [];
    $tags = [];
    foreach ($raw as $tag) {
        if (!is_string($tag)) return null;   // reject non-string → 422
        $t = trim($tag);
        if ($t !== '') $tags[] = strtolower($t);
    }
    return $tags;
}
```

---

## IDOR / Ownership Pattern

All read and write operations scope to `user_id`. Return 404 (not 403) on reads to avoid revealing existence; return 403 on writes so the user knows the resource exists but they lack permission:

```php
// Read: 404 to prevent information disclosure
if ((int) $note['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Note not found.');
}

// Write: 403 when resource exists but not owned
if ((int) $note['user_id'] !== $userId) {
    return 'forbidden';
}
```

---

## Partial Update (PUT)

Accept `null` for any field to mean "no change":

```php
$title    = isset($body['title']) ? trim((string) $body['title']) : null;
$noteBody = isset($body['body']) ? (string) $body['body'] : null;
$tags     = (isset($body['tags'])) ? $this->parseTags($body['tags']) : null;
```

In the repository, only update fields that are non-null.

---

## HTTP Status Codes

| Situation | Status |
|-----------|--------|
| Note created | 201 |
| Note retrieved / list | 200 |
| Note updated / deleted | 200 |
| No X-User-Id | 400 |
| Empty title | 422 |
| Non-string tag values | 422 |
| Note not found (or IDOR) | 404 |
| Update/delete other's note | 403 |
