# How-to: Password Authentication with Argon2id

> **FT reference**: FT331 (`NENE2-FT/pwdlog`) — User registration and login with Argon2id password hashing, password/hash never exposed in responses, user enumeration prevention (same 401 for wrong password and unknown email), algorithm-migration rehash, 14 tests / 40 assertions PASS.

This guide shows how to build secure password-based authentication: store passwords safely with Argon2id, never leak credentials in responses, and prevent attackers from enumerating registered email addresses.

## Schema

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);
```

`password_hash` stores the full Argon2id output string (e.g. `$argon2id$v=19$m=65536,...`). **Never store plaintext or MD5/SHA-1.**

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/register` | Register a new user |
| `POST` | `/login` | Authenticate and return user data |

## Register

```php
POST /register
{"email": "alice@example.com", "password": "correct-horse"}

→ 201
{"id": 1, "email": "alice@example.com", "created_at": "2026-05-27T09:00:00Z"}
```

**`password` and `password_hash` are NEVER returned** in the response — not even masked or truncated.

### Validation

```php
POST /register  {"email": "alice@example.com", "password": "short"}
→ 422  // password too short (min 8 characters)

POST /register  {"email": "not-an-email", "password": "correct-horse"}
→ 422  // invalid email format

POST /register  {"email": "alice@example.com"}
→ 400  // password field missing

POST /register  {"email": "alice@example.com", "password": "battery-staple"}
// (after alice is already registered)
→ 409  {"type": ".../email-taken", "detail": "Email already registered"}
```

## Login

```php
POST /login
{"email": "alice@example.com", "password": "correct-horse"}

→ 200
{"id": 1, "email": "alice@example.com", "created_at": "..."}
// password_hash not returned
```

### User Enumeration Prevention

```php
// Wrong password for known email
POST /login  {"email": "alice@example.com", "password": "wrong"}
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}

// Unknown email
POST /login  {"email": "ghost@example.com", "password": "any"}
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}
```

**Both cases return the same 401 with the identical `detail` message.** Returning 404 for unknown email would let attackers probe the user database.

```php
// Test: same detail string
$this->assertSame($wrongPasswordBody['detail'], $unknownEmailBody['detail']);
```

## Implementation

### Password Storage — Argon2id

```php
// Registration
$hash = password_hash($plaintext, PASSWORD_ARGON2ID);
// Stores: $argon2id$v=19$m=65536,t=4,p=1$...

// Never store:
// md5($plaintext)          — reversible in seconds
// sha1($plaintext)         — rainbow table attack
// $plaintext               — plaintext storage
```

PHP's `password_hash(PASSWORD_ARGON2ID)` automatically:
- Generates a random salt per hash
- Stores algorithm, parameters, salt and digest in one string
- Resists GPU brute force (memory-hard)

### Verification — Constant-Time

```php
$row = $this->repo->findByEmail($email);

if ($row === null || !password_verify($plaintext, $row['password_hash'])) {
    // Same response whether email is unknown or password is wrong
    return $this->problems->create('invalid-credentials', 'Invalid email or password', 401);
}
```

`password_verify()` is constant-time and works across algorithm families (bcrypt, Argon2id, etc.).

### Algorithm Migration Rehash

When upgrading from bcrypt to Argon2id, rehash on successful login:

```php
if (password_needs_rehash($row['password_hash'], PASSWORD_ARGON2ID)) {
    $newHash = password_hash($plaintext, PASSWORD_ARGON2ID);
    $this->repo->updateHash($row['id'], $newHash);
}
```

Users are silently migrated to the stronger algorithm the next time they log in — no forced password reset required.

### Never Return Credentials

```php
private function toPublic(array $user): array
{
    // Explicitly drop sensitive fields
    unset($user['password_hash']);
    return $user;
}
```

Apply `toPublic()` to every response: register 201, login 200, and any profile endpoint.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Return 404 for unknown email on login | User enumeration: attacker discovers which emails are registered |
| Return different `detail` message for wrong password vs unknown email | Leaks which condition failed |
| Store password as MD5 or SHA-1 | Rainbow table attack breaks all passwords within hours |
| Store password as bcrypt without migration path | Cannot upgrade to stronger algorithm without forced reset |
| Return `password_hash` in any response | Hash can be used for offline brute-force |
| Skip `password_needs_rehash()` on login | Legacy weak hashes persist forever even after algorithm upgrade |
| Use `===` to compare hashes | Timing attack reveals hash bytes; always use `password_verify()` |
