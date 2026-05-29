---
title: "How-to: Data Export API"
category: security
tags: [gdpr, data-export, pii, token, async]
difficulty: intermediate
related: [data-masking, personal-data-export, privacy-consent-management]
---

# How-to: Data Export API

> **FT reference**: FT312 (`NENE2-FT/exportlog`) — Data export (GDPR-style): async `pending→ready` state machine via token-based download, PII exclusion via `toPublicArray()` (password_hash and phone never in GET response or export payload), ARGON2ID password hashing, 64-hex-char export token, 410 Gone for expired exports, 409 for pending download attempt, 19 tests / 32 assertions PASS.

This guide shows how to build a user data export system (GDPR Article 20 portability) where exports are async, protected by tokens, and PII-sensitive fields are never leaked.

## Schema

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    name          TEXT    NOT NULL,
    phone         TEXT    NOT NULL DEFAULT '',
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE data_exports (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,  -- 64 hex chars
    status     TEXT    NOT NULL DEFAULT 'pending',
    payload    TEXT,                     -- JSON, set when status='ready'
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`token` is a 64-character hex string for download URL. `payload` is null until the export is processed.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/users` | Register user |
| `GET` | `/users/{id}` | Get user (PII excluded) |
| `POST` | `/users/{id}/export` | Request data export → 202 |
| `POST` | `/exports/{token}/process` | Process export (async worker) |
| `GET` | `/exports/{token}` | Download completed export |

## PII Exclusion — toPublicArray()

```php
final class User
{
    public function toPublicArray(): array
    {
        return [
            'id'         => $this->id,
            'email'      => $this->email,
            'name'       => $this->name,
            'created_at' => $this->createdAt,
            // phone and password_hash intentionally excluded from public view
        ];
    }
}
```

The `GET /users/{id}` response calls `toPublicArray()` — never the full array. `phone` and `password_hash` are stored but never returned via API.

The same exclusion applies to the export payload: the export is built from `toPublicArray()` (or equivalent), not from a raw DB row.

## Password Hashing — ARGON2ID

```php
$passwordHash = password_hash($password, PASSWORD_ARGON2ID);
```

ARGON2ID is the recommended modern algorithm (memory-hard, resistant to GPU attacks). `PASSWORD_BCRYPT` is acceptable but weaker against GPU cracking.

## Async Export — pending → ready

```
POST /users/{id}/export  →  202 Accepted
  → creates data_exports row: status='pending', token='<64hex>'

POST /exports/{token}/process  →  200 OK
  → builds payload, sets status='ready'

GET /exports/{token}  →  200 OK (download)
  → returns payload if status='ready'
```

**Export token generation:**
```php
$token = bin2hex(random_bytes(32)); // 64 hex chars
```

**Process handler:**
```php
if ($export->status === 'ready') {
    return 200; // already processed, idempotent
}
if ($export->expiresAt < date('c')) {
    return 410; // expired — don't process
}
// Build and store payload
$this->repo->markReady($export->token, json_encode($export->user->toPublicArray()));
```

## Status Checks — 409 and 410

```php
// Download handler
if ($export->expiresAt < date('c')) {
    return $this->problems->create($request, 'gone', 'Export has expired.', 410, '');
}

if ($export->status !== 'ready') {
    return $this->problems->create($request, 'conflict', 'Export is not yet ready.', 409, '');
}
```

| State | Download response |
|---|---|
| `pending` | 409 Conflict |
| `ready` (not expired) | 200 OK with payload |
| `ready` (expired) | 410 Gone |

410 Gone is used for expired resources (GDPR: export data shouldn't persist indefinitely).

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Include `password_hash` in GET response | Password hash exposed; enables offline cracking |
| Include `phone` in GET response without auth | PII leak; phone numbers exposed to anyone who knows user ID |
| Include `password_hash` in export payload | GDPR violation; export is a user-facing data portability document |
| Use `PASSWORD_MD5` or `PASSWORD_DEFAULT` | Weak password hashing; upgrade to ARGON2ID |
| Return 404 for expired exports (not 410) | 404 hides the distinction between "never existed" and "expired" |
| Return 200 for pending download | Client thinks export is ready; receives empty or broken payload |
| Short export token (< 64 chars) | Guessable token; anyone can download any user's export |
| No `expires_at` on exports | Exports persist indefinitely; GDPR compliance issue |
