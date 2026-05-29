---
title: "Personal Data Export"
category: security
tags: [gdpr, data-export, pii, download-token, expiry]
difficulty: intermediate
related: [pii-masking, data-export-api]
---

# Personal Data Export

A GDPR-style data export lets users download all their personal data. The primary concerns are: sensitive field exclusion from the export payload, secure download tokens, and expiry enforcement.

## Core components

- **Export job**: a record linking a user to an opaque download token, with status (pending → ready) and an expiry timestamp.
- **Process step**: a worker-side operation that builds the payload and marks the job ready.
- **Download**: fetches the payload by token, checking expiry before serving.

## Schema

```sql
CREATE TABLE data_exports (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    status     TEXT    NOT NULL DEFAULT 'pending',
    payload    TEXT,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

## Token generation

Use `bin2hex(random_bytes(32))` — 64 hex characters, 256 bits of entropy. Sequential IDs, timestamps, or MD5-based tokens are guessable and must not be used for download tokens.

```php
$token = bin2hex(random_bytes(32));
```

## Sensitive field exclusion

The export payload must never contain credentials or fields the user did not explicitly consent to export. Exclude at the repository level, not the HTTP layer:

```php
public function processExport(string $token, User $user, array $activities, string $now): DataExport
{
    $payload = json_encode([
        'exported_at' => $now,
        'user' => [
            'id'         => $user->id,
            'email'      => $user->email,
            'name'       => $user->name,
            'created_at' => $user->createdAt,
            // password_hash intentionally excluded
            // phone intentionally excluded (re-consent required for PII)
        ],
        'activities' => $activities,
    ], JSON_THROW_ON_ERROR);
    // ...
}
```

Apply the same exclusion to the public profile endpoint — `phone`, `password_hash`, and any internal fields should not appear in `GET /users/{id}` responses either.

## Expiry enforcement

Enforce expiry in **both** the download endpoint and the process endpoint:

```php
// In downloadExport:
if ($export->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Export has expired.', 410, '');
}

// In processExport — CRITICAL: also check here
if ($export->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Export request has expired. Please request a new export.', 410, '');
}
```

Without the check in `processExport`, a worker that receives a stale job would write the user's data to DB even though the download window has closed, creating orphaned records with sensitive payload data.

## Status flow

```
pending ──(process called, not expired)──▶ ready ──(download called)──▶ [payload served]
   │                                                       │
   └──(process called, expired)──▶ 410                    └──(expired)──▶ 410
```

## Download: 410 Gone vs 404 Not Found

- **404**: the token does not exist in the database.
- **410 Gone**: the token exists but has expired. This is the correct status — the resource existed and has since been removed. Clients can use this signal to prompt the user to request a new export.

## Design decisions

**Why a separate `process` step rather than synchronous generation?**
Export payloads can be large (years of activity data). Generating synchronously in the HTTP handler risks timeouts and ties up a worker. The async pattern lets the user request and check later. For this FT, the process step is exposed as an API to simulate worker invocation.

**Why use the token as the download URL rather than the export ID?**
A sequential integer ID is IDOR-vulnerable — user 1 could download user 2's export by incrementing the ID. An opaque random token makes the download URL unguessable.

**Should `process` be a public endpoint?**
In production, no. The process endpoint should be called only by internal workers (via API key, internal network, or queue). In this FT it's exposed for testability. The token's entropy provides some protection but is not a substitute for proper worker authentication.
