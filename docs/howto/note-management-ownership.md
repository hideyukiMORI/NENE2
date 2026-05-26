# How-to: Note Management with Ownership

> **FT reference**: FT240 (`NENE2-FT/noteslog`) — Note Management API
> **ATK**: FT240 — cracker-mindset attack test (ATK-01 through ATK-12)

Demonstrates a note management API with owner-scoped operations, `X-Auth-User` header
identification, IDOR prevention via `WHERE id = ? AND owner_id = ?`, and field-merge
updates that preserve unspecified fields.

---

## Routes

| Method   | Path           | Description                                          |
|----------|----------------|------------------------------------------------------|
| `POST`   | `/notes`       | Create a note (requires `X-Auth-User` header)        |
| `GET`    | `/notes`       | List notes owned by the caller                       |
| `GET`    | `/notes/{id}`  | Get a single note (404 if not found or not owner)    |
| `PUT`    | `/notes/{id}`  | Update a note (field-merge: omitted fields are kept) |
| `DELETE` | `/notes/{id}`  | Delete a note (404 if not found or not owner)        |

---

## `X-Auth-User` header identification

The API uses a minimal `X-Auth-User` string header as the caller's identity:

```php
private function resolveAuthUser(ServerRequestInterface $request): ?string
{
    $userId = trim($request->getHeaderLine('X-Auth-User'));

    return $userId !== '' ? $userId : null;
}
```

`trim()` strips leading/trailing whitespace. An empty-after-trim header → `null` →
`401 Unauthorized`. Any non-empty string is accepted as a valid user ID — there is
no token verification.

This is intentionally weak for demo purposes. In production, replace with verified
JWT claims or session-cookie-backed sessions.

---

## IDOR prevention: `WHERE id = ? AND owner_id = ?`

Every operation that touches a specific note includes `owner_id` in the query:

```php
/**
 * Returns the note only if it belongs to the given owner.
 * Returns null for both "not found" and "wrong owner" — callers return 404 in both cases
 * to prevent IDOR information leakage (do not expose whether a resource exists).
 */
public function findByIdAndOwner(int $id, string $ownerId): ?Note
{
    $row = $this->db->fetchOne(
        'SELECT * FROM notes WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    );

    return $row !== null ? $this->hydrate($row) : null;
}
```

The method returns `null` for both "not found" and "wrong owner". The controller uses
the same `404 Not Found` response in both cases:

```php
$note = $this->repo->findByIdAndOwner($id, $authUser);

if ($note === null) {
    // 404 not 403: do not reveal whether the resource exists (IDOR prevention)
    return $this->problems->create($request, 'not-found', 'Note Not Found', 404, '');
}
```

Returning `403 Forbidden` would confirm that the resource exists — the `404` approach
prevents enumeration attacks. A caller learns nothing about other users' notes.

---

## Field-merge update

`PUT /notes/{id}` keeps existing values for fields omitted from the request body:

```php
$title    = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : $note->title;
$noteBody = isset($body['body'])  && is_string($body['body'])  ? $body['body']        : $note->body;

$this->repo->update($id, $authUser, $title, $noteBody);
$updated = new Note($note->id, $note->ownerId, $title, $noteBody, $note->createdAt);
```

If only `title` is provided, `body` keeps its current value — and vice versa. This
differs from a full replacement (`PUT` semantics) — it behaves closer to `PATCH`. For
strict `PUT` semantics, require both fields and return `422` if either is absent.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_notes_owner ON notes (owner_id);
```

`body` defaults to `''` — no nullable column for the text body. `owner_id` is a free
string (the `X-Auth-User` value); no foreign key to a users table exists.

---

## ATK — Cracker-mindset attack test (FT240)

### ATK-01 — `X-Auth-User` is trivially forgeable

**Attack**: Impersonate another user by sending their user ID in the header.

```bash
curl -s -X GET http://localhost:8080/notes \
  -H 'X-Auth-User: alice'

curl -s -X GET http://localhost:8080/notes \
  -H 'X-Auth-User: bob'
```

**Observed**: Each request returns notes owned by the user ID in the header. Any
caller can impersonate any user by knowing or guessing their ID string.

**Verdict**: **EXPOSED** — the header carries no cryptographic proof of identity. Use
signed JWT tokens or session cookies for production auth.

---

### ATK-02 — Newline injection in `X-Auth-User`

**Attack**: Embed HTTP header-injection characters (CR/LF) in the header value.

```
X-Auth-User: alice\r\nX-Injected: evil
```

**Observed**: PSR-7 (Nyholm) strips or rejects invalid header characters. The header
value is a plain string — CRLF injection at the HTTP layer is handled by the server
(Swoole, Apache, Nginx) before it reaches the application. `trim()` removes leading/
trailing whitespace but does not add a further defense against embedded control chars.

**Verdict**: **BLOCKED** in practice — HTTP servers reject malformed headers before
they reach the application layer.

---

### ATK-03 — IDOR: read another user's note

**Attack**: Guess or enumerate note IDs belonging to another user.

```bash
curl -s http://localhost:8080/notes/1 -H 'X-Auth-User: bob'
# Note 1 was created by alice
```

**Observed**: `findByIdAndOwner(1, 'bob')` finds no row matching `id = 1 AND owner_id = 'bob'`
→ returns `null` → `404 Not Found`. Bob cannot determine that note 1 exists.

**Verdict**: **BLOCKED** — ownership-scoped query + 404 prevents IDOR.

---

### ATK-04 — SQL injection via title or body

**Attack**: Embed SQL metacharacters in the request body.

```json
{"title": "'; DROP TABLE notes; --", "body": "\" OR \"1\"=\"1"}
```

**Observed**: The values are stored as parameterised `?` values — no string
concatenation with SQL. The injection payloads are stored as literal text.

**Verdict**: **BLOCKED** — parameterised queries prevent all SQL injection via body fields.

---

### ATK-05 — Empty title

**Attack**: Create a note with a whitespace-only or empty title.

```json
{"title": "   "}
{"title": ""}
```

**Observed**: `trim($body['title'])` reduces both to `""`. The `title === ''` check
fires → `422 Unprocessable Entity`.

**Verdict**: **BLOCKED** — `trim()` + empty-string check handles whitespace-only input.

---

### ATK-06 — Missing `X-Auth-User` header

**Attack**: Send a request without the `X-Auth-User` header.

```bash
curl -s http://localhost:8080/notes
```

**Observed**: `getHeaderLine('X-Auth-User')` returns `""`. After `trim()` it's still
`""`. `$userId !== ''` fails → `resolveAuthUser()` returns `null` → `401 Unauthorized`
with a structured Problem Details response.

**Verdict**: **BLOCKED** — missing header is treated as unauthenticated.

---

### ATK-07 — Impersonation via arbitrary `X-Auth-User` value

**Attack**: Create notes as a privileged user ID string.

```bash
# Assuming 'admin' is a special user
curl -s -X POST http://localhost:8080/notes \
  -H 'X-Auth-User: admin' \
  -H 'Content-Type: application/json' \
  -d '{"title":"Admin note"}'
```

**Observed**: `201 Created` — the note is created with `owner_id = 'admin'`. Any
string is accepted as the caller's identity.

**Verdict**: **EXPOSED** (same root as ATK-01). Without cryptographic auth, there is
no way to distinguish a real admin from an attacker who knows the string `"admin"`.

---

### ATK-08 — XSS payload in title or body

**Attack**: Store a script tag.

```json
{"title": "<script>alert(1)</script>", "body": "<img src=x onerror=alert(1)>"}
```

**Observed**: Content is stored as-is and returned verbatim in JSON. The JSON API does
not HTML-encode output.

**Verdict**: **ACCEPTED BY DESIGN** — JSON APIs return raw content. The rendering layer
must sanitise before inserting into HTML. Document this expectation for API consumers.

---

### ATK-09 — Partial update loses unintended fields

**Attack**: Attempt to overwrite `body` to empty by omitting it from the update.

```json
{"title": "New title"}
// Caller expects body to be cleared; actually it's preserved
```

**Observed**: The field-merge logic preserves `body` if absent from the request:
`$noteBody = isset($body['body']) ? $body['body'] : $note->body`. The body is
unchanged — this matches intent for a merge-update API but may surprise callers
expecting full replacement (`PUT` semantics).

**Verdict**: **ACCEPTED BY DESIGN** — documented merge-update behaviour. If strict
`PUT` semantics are desired, require all fields.

---

### ATK-10 — Non-numeric note ID

**Attack**: Pass a string or float as `{id}`.

```
GET /notes/abc
GET /notes/1.5
```

**Observed**: `(int) 'abc'` = 0, `(int) '1.5'` = 1.
- `abc` → `findByIdAndOwner(0, ...)` → no row → `404 Not Found`.
- `1.5` → `findByIdAndOwner(1, ...)` → if note 1 is owned by caller, returns it.

**Verdict**: **PARTIALLY BLOCKED** — non-numeric strings map to 404. Floats are
silently truncated. Add `ctype_digit()` guard for strict validation.

---

### ATK-11 — Delete non-existent or unowned note

**Attack**: DELETE a note ID that doesn't exist or belongs to another user.

```bash
curl -s -X DELETE http://localhost:8080/notes/99999 -H 'X-Auth-User: alice'
curl -s -X DELETE http://localhost:8080/notes/1    -H 'X-Auth-User: eve'
# (note 1 belongs to alice)
```

**Observed**: The repository runs `DELETE FROM notes WHERE id = ? AND owner_id = ?`.
If no rows match (non-existent or wrong owner), `$deleted = false` → `404 Not Found`.
Eve's attempt returns the same 404 as a non-existent note.

**Verdict**: **BLOCKED** — owner-scoped DELETE + 404 response prevents cross-user deletion.

---

### ATK-12 — Whitespace-only `X-Auth-User`

**Attack**: Send a header containing only spaces or tabs.

```
X-Auth-User:    
X-Auth-User: \t
```

**Observed**: `trim('   ')` = `""` → `$userId !== ''` fails → `401 Unauthorized`.

**Verdict**: **BLOCKED** — `trim()` normalises whitespace-only headers to empty.

---

## ATK summary

| # | Attack vector | Verdict |
|---|---------------|---------|
| ATK-01 | X-Auth-User is trivially forgeable | EXPOSED |
| ATK-02 | Newline injection in X-Auth-User | BLOCKED |
| ATK-03 | IDOR: read another user's note | BLOCKED |
| ATK-04 | SQL injection via title/body | BLOCKED |
| ATK-05 | Empty title | BLOCKED |
| ATK-06 | Missing X-Auth-User header | BLOCKED |
| ATK-07 | Impersonation via arbitrary header value | EXPOSED |
| ATK-08 | XSS in title/body | ACCEPTED BY DESIGN |
| ATK-09 | Partial update field-merge surprise | ACCEPTED BY DESIGN |
| ATK-10 | Non-numeric note ID | PARTIALLY BLOCKED |
| ATK-11 | Delete unowned/non-existent note | BLOCKED |
| ATK-12 | Whitespace-only X-Auth-User | BLOCKED |

**Real vulnerabilities to fix before production**:
1. **ATK-01 / ATK-07** — Replace `X-Auth-User` with signed JWT or session verification
2. **ATK-10** — Add `ctype_digit()` guard for ID path parameters

---

## Related howtos

- [`use-bearer-auth.md`](use-bearer-auth.md) — signed Bearer token authentication
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR prevention patterns
- [`jwt-authentication.md`](jwt-authentication.md) — JWT verification for user identification
- [`scheduled-reminders.md`](scheduled-reminders.md) — V::userId() header validation pattern
