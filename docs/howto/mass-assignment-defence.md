---
title: "How-to: Mass Assignment Defence with Explicit DTO"
category: security
tags: [mass-assignment, dto, whitelist, input-validation]
difficulty: intermediate
related: [mass-assignment, enforce-resource-ownership]
ft: FT256
---

# How-to: Mass Assignment Defence with Explicit DTO

> **FT reference**: FT256 (`NENE2-FT/masslog`) — Mass assignment defence pattern with explicit DTO whitelisting
> **ATK**: FT256 — cracker-mindset attack test (ATK-01 through ATK-12)

Demonstrates how to prevent mass assignment vulnerabilities using an explicit readonly DTO
that whitelists only the fields callers are permitted to set. Server-controlled fields
(`role`, `is_active`, `created_at`, `id`) are excluded from the DTO and hard-coded in
the repository. Includes a full cracker-mindset attack assessment.

---

## Routes

| Method | Path     | Description               |
|--------|----------|---------------------------|
| `POST` | `/users` | Create a user (role=user) |
| `GET`  | `/users` | List all users            |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS users (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    name      TEXT    NOT NULL,
    email     TEXT    NOT NULL UNIQUE,
    role      TEXT    NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'admin')),
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT   NOT NULL
);
```

`CHECK(role IN ('user', 'admin'))` is a DB-level safety net. The application always
writes `'user'` to `role` on creation, so the constraint is never triggered in normal
operation — it guards against bugs or direct DB access.

---

## The explicit DTO: field whitelisting

```php
/**
 * Explicit DTO for user creation — only name and email are accepted from user input.
 *
 * role and is_active are intentionally excluded: they must be set by server-side
 * business logic, never from the request body. This is the mass-assignment defence.
 */
final readonly class CreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

The DTO has exactly two fields — `name` and `email`. There is no `role`, `is_active`,
`created_at`, or `id` field. An attacker cannot inject these fields because the
constructor simply doesn't accept them.

**Why this is better than a blocklist**:

| Approach | Security model | Failure mode |
|---|---|---|
| Explicit allowlist (DTO) | Reject unknown by default | Safe — new fields must be explicitly added |
| Blocklist (`unset($body['role'])`) | Block known-bad | Unsafe — new sensitive fields are forgotten |
| `array_intersect_key` | Filter to known keys | Acceptable — same as allowlist if keys are complete |

An explicit DTO fails safely: adding a new sensitive column to the schema does not
automatically expose it — the developer must explicitly add it to the DTO.

---

## Controller: explicit field extraction

```php
private function createUser(ServerRequestInterface $request): ResponseInterface
{
    $body = json_decode((string) $request->getBody(), true);
    if (!is_array($body)) {
        return $this->problems->create($request, 'invalid-body', '...', 400);
    }

    $errors = [];

    if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
        $errors[] = ['field' => 'name', 'code' => 'required', 'message' => 'name is required.'];
    }
    if (!isset($body['email']) || !is_string($body['email']) || !filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = ['field' => 'email', 'code' => 'invalid-email', 'message' => 'email must be a valid email address.'];
    }

    if ($errors !== []) {
        return $this->problems->create($request, 'validation-failed', 'Validation failed.', 422, null, ['errors' => $errors]);
    }

    // Only allowed fields are mapped — extra fields (role, is_active, etc.) are silently discarded
    $input = new CreateUserInput(
        name:  trim((string) $body['name']),
        email: strtolower(trim((string) $body['email'])),
    );

    $user = $this->repo->create($input);
    return $this->json->create([...], 201);
}
```

The controller reads `$body['name']` and `$body['email']` explicitly. All other keys in
`$body` are silently discarded — they are never read or passed anywhere.

Email is normalized to lowercase (`strtolower`) before creating the DTO, preventing
duplicate emails that differ only in case.

---

## Repository: server-controlled fields

```php
public function create(CreateUserInput $input): User
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $id = $this->executor->insert(
        'INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
        [$input->name, $input->email, 'user', 1, $now],  // role and is_active are hard-coded
    );

    return new User(
        id:        $id,
        name:      $input->name,
        email:     $input->email,
        role:      'user',    // hard-coded, not from $input
        isActive:  true,      // hard-coded, not from $input
        createdAt: $now,
    );
}
```

`'user'` and `1` are literal values in the INSERT. There is no way for user input to
influence `role` or `is_active`. The `CreateUserInput` DTO type signature enforces this
at the PHP type level.

---

## ATK — Cracker-mindset attack test (FT256)

### ATK-01 — Role escalation: inject `role: "admin"` in request body

**Attack**: Include `role` in the request body to create an admin user.

```json
{"name": "Attacker", "email": "attacker@example.com", "role": "admin"}
```

**Observed**: `role` is not a field in `CreateUserInput`. The controller only reads
`name` and `email` from `$body`. The extra key is silently discarded. The created user
has `role = 'user'`.

**Verdict**: **BLOCKED** — explicit DTO field whitelist prevents privilege escalation.

---

### ATK-02 — Account state manipulation: inject `is_active: false`

**Attack**: Create a user with `is_active = false` to create a disabled account or
test if the field is writable.

```json
{"name": "Bob", "email": "bob@example.com", "is_active": false}
```

**Observed**: `is_active` is not in `CreateUserInput`. The created user has
`is_active = true` (hard-coded in the INSERT).

**Verdict**: **BLOCKED** — `is_active` is never read from the request.

---

### ATK-03 — Timestamp manipulation: inject `created_at`

**Attack**: Backdate the user's creation timestamp.

```json
{"name": "Carol", "email": "carol@example.com", "created_at": "2000-01-01 00:00:00"}
```

**Observed**: `created_at` is not in `CreateUserInput`. The repository generates
`$now` from `DateTimeImmutable` at write time.

**Verdict**: **BLOCKED** — audit timestamps are server-generated, not client-supplied.

---

### ATK-04 — ID hijacking: inject `id: 9999`

**Attack**: Pre-select a primary key to overwrite an existing record or claim a
known ID.

```json
{"name": "Dave", "email": "dave@example.com", "id": 9999}
```

**Observed**: `id` is not in `CreateUserInput`. The INSERT uses `AUTOINCREMENT` — the
`id` is assigned by SQLite, not from any user-supplied value.

**Verdict**: **BLOCKED** — primary key assignment is always server-side.

---

### ATK-05 — SQL injection via name or email

**Attack**: Embed SQL metacharacters.

```json
{"name": "'; DROP TABLE users; --", "email": "sql@example.com"}
```

**Observed**: Both fields are bound as parameterized `?` placeholders in the INSERT.
The injection payload is stored as literal text.

**Verdict**: **BLOCKED** — parameterized queries prevent SQL injection.

---

### ATK-06 — Email case bypass: submit uppercase email

**Attack**: Register `ADMIN@EXAMPLE.COM` as a different user from `admin@example.com`.

```json
{"name": "Eve", "email": "ADMIN@EXAMPLE.COM"}
```

**Observed**: The controller applies `strtolower()` before passing to the DTO. Both
`ADMIN@EXAMPLE.COM` and `admin@example.com` normalize to `admin@example.com`. The
`UNIQUE` constraint prevents a second registration.

**Verdict**: **BLOCKED** — case normalization + UNIQUE constraint prevent duplicate accounts.

---

### ATK-07 — Duplicate email: register the same address twice

**Attack**: Register the same email address to trigger an error or create duplicate accounts.

```json
{"name": "Frank", "email": "frank@example.com"}
{"name": "FrankDuplicate", "email": "frank@example.com"}
```

**Observed**: The first request succeeds with `201`. The second request triggers a
SQLite `UNIQUE` constraint violation. The current implementation does not catch this
exception — it propagates as an unhandled error.

**Verdict**: **EXPOSED** — catch the unique constraint violation and return a structured
`409 Conflict` or `422 Unprocessable Entity` response. Leaking raw DB errors is a
security and UX issue.

---

### ATK-08 — XSS payload in name or email

**Attack**: Store a script tag.

```json
{"name": "<script>alert(1)</script>", "email": "xss@example.com"}
```

**Observed**: Content is stored as-is and returned verbatim in JSON. The API does not
HTML-encode output.

**Verdict**: **ACCEPTED BY DESIGN** — JSON APIs return raw content. The rendering
layer must sanitize before inserting into HTML.

---

### ATK-09 — Missing required fields

**Attack**: Omit `name` or `email`.

```json
{"email": "missing@example.com"}
{"name": "NoEmail"}
{}
```

**Observed**: Each returns `422 Unprocessable Entity` with a structured `errors` array
identifying the missing field by name.

**Verdict**: **BLOCKED** — explicit presence checks for each required field.

---

### ATK-10 — Type confusion: submit name as integer

**Attack**: Send `name` as a JSON number.

```json
{"name": 12345, "email": "typed@example.com"}
```

**Observed**: `is_string($body['name'])` returns `false` for integer values. The request
returns `422` with `name is required`.

**Verdict**: **BLOCKED** — `is_string()` rejects non-string types.

---

### ATK-11 — Very long name or email

**Attack**: Submit a name or email with 10,000+ characters.

```json
{"name": "aaaa...aaaa (10000 chars)", "email": "x@example.com"}
```

**Observed**: The request succeeds with `201`. No length validation is applied to
`name` or `email`. SQLite stores TEXT with no inherent length limit.

**Verdict**: **EXPOSED** — add length validation (e.g., `mb_strlen($name) > 255 → 422`).
Rely on request-size middleware as the outer limit.

---

### ATK-12 — Multiple role values: inject as array

**Attack**: Submit `role` as an array rather than a string.

```json
{"name": "Grace", "email": "grace@example.com", "role": ["admin", "superuser"]}
```

**Observed**: `role` is not read from `$body` at all. Whether it is a string, array,
or null has no effect on the created user.

**Verdict**: **BLOCKED** — the DTO excludes `role` entirely; its type is irrelevant.

---

## ATK summary

| # | Attack vector | Verdict |
|---|---------------|---------|
| ATK-01 | Role escalation via `role: "admin"` | BLOCKED |
| ATK-02 | Account state manipulation via `is_active: false` | BLOCKED |
| ATK-03 | Timestamp backdate via `created_at` | BLOCKED |
| ATK-04 | ID hijacking via `id: 9999` | BLOCKED |
| ATK-05 | SQL injection via name/email | BLOCKED |
| ATK-06 | Email case bypass (`ADMIN@EXAMPLE.COM`) | BLOCKED |
| ATK-07 | Duplicate email (no graceful error) | EXPOSED |
| ATK-08 | XSS payload in name | ACCEPTED BY DESIGN |
| ATK-09 | Missing required fields | BLOCKED |
| ATK-10 | Type confusion (name as integer) | BLOCKED |
| ATK-11 | Very long name or email (no length limit) | EXPOSED |
| ATK-12 | Role as array | BLOCKED |

**Real vulnerabilities to fix before production**:
1. **ATK-07** — Catch UNIQUE constraint violation; return `409 Conflict` with a user-facing message
2. **ATK-11** — Add `mb_strlen` length validation for `name` and `email`

---

## Related howtos

- [`mass-assignment.md`](mass-assignment.md) — mass assignment defence patterns overview
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — ownership-scoped queries to prevent IDOR
- [`rbac.md`](rbac.md) — role-based access control with JWT claims
- [`user-profile-management.md`](user-profile-management.md) — profile update with field whitelisting
