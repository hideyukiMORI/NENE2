# User Invitation System

Invite new users by email, enforce expiry, and prevent abuse with token-based invitations.

## Overview

An invitation system lets existing users sponsor new account creation. The key invariants are:

- Tokens are cryptographically random and non-guessable.
- Expiry is checked at both read and write time.
- Only the original inviter can cancel an invitation.
- Accepted and cancelled tokens cannot be reused.

## Database Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE invitations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    inviter_id  INTEGER NOT NULL,
    email       TEXT    NOT NULL,
    token       TEXT    NOT NULL UNIQUE,
    status      TEXT    NOT NULL DEFAULT 'pending',
    expires_at  TEXT    NOT NULL,
    accepted_at TEXT,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (inviter_id) REFERENCES users(id)
);
```

## Token Generation

Always use `bin2hex(random_bytes(32))` — 64 hex characters, 256 bits of entropy:

```php
$token = bin2hex(random_bytes(32));
```

Never use sequential IDs, UUIDs, or short strings as invite tokens. A guessable token lets an attacker accept any pending invitation.

## Sending an Invitation

Before creating the invitation, verify the target email is not already registered:

```php
// Prevent inviting already-registered users
if ($this->repo->findUserByEmail($email) !== null) {
    return $this->problems->create($request, 'conflict', 'Email already registered.', 409, '');
}

$expiresAt = (new \DateTimeImmutable())->modify('+24 hours')->format('Y-m-d H:i:s');
$token     = bin2hex(random_bytes(32));
$invite    = $this->repo->createInvitation($inviterId, $email, $token, $expiresAt, $now);
```

Returning 409 when inviting a registered email reveals registration status to the inviter. This is acceptable in invite-only systems where inviters are trusted users. In fully public systems, consider unifying the response to 202.

## Accepting an Invitation

Check expiry **before** checking status — a pending-but-expired invitation must return 410, not 409:

```php
$invite = $this->repo->findByTokenOrNull($token);

if ($invite === null) {
    return $this->problems->create($request, 'not-found', 'Invitation not found.', 404, '');
}

$now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

if ($invite->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Invitation has expired.', 410, '');
}

if (!$invite->isPending()) {
    return $this->problems->create($request, 'conflict', 'Invitation is no longer valid.', 409, '');
}
```

`isExpired` compares the current timestamp string directly — SQLite datetime strings sort lexicographically when stored as `Y-m-d H:i:s`:

```php
public function isExpired(string $now): bool
{
    return $now >= $this->expiresAt;
}

public function isPending(): bool
{
    return $this->status === 'pending';
}
```

## Cancelling an Invitation

Ownership is enforced using the `inviter_id` from the request body (since there is no session/JWT middleware in this minimal example). In production, derive the actor from an authenticated token instead:

```php
if ($invite->inviterId !== $inviterId) {
    return $this->problems->create($request, 'forbidden', 'Only the inviter may cancel this invitation.', 403, '');
}

if (!$invite->isPending()) {
    return $this->problems->create($request, 'conflict', 'Invitation is already ' . $invite->status . '.', 409, '');
}
```

Return 403 (not 404) when the ownership check fails — obscuring the invitation's existence would hide the fact that the attacker found a real token, but 403 is the correct semantic here since the resource was found but the action is forbidden.

## Status Machine

```
pending ──accept──► accepted
pending ──cancel──► cancelled
```

Once an invitation leaves `pending`, no further transitions are allowed. Attempting to accept an `accepted` or `cancelled` invitation returns 409.

## Security Properties

| Property | Implementation |
|---|---|
| Token entropy | `bin2hex(random_bytes(32))` — 256 bits |
| Token uniqueness | UNIQUE constraint on `invitations.token` |
| Expiry at read | checked in handler before any write |
| Reuse prevention | `isPending()` guard before accept/cancel |
| Owner enforcement | `inviter_id` equality check → 403 |
| No email PII leak | 409 body does not expose the invited email |
| SQL injection | PDO parameterised queries throughout |

## Route Summary

| Method | Path | Description |
|---|---|---|
| `POST` | `/users` | Create a user account |
| `POST` | `/users/{id}/invitations` | Send an invitation |
| `GET` | `/invitations/{token}` | View an invitation |
| `POST` | `/invitations/{token}/accept` | Accept an invitation |
| `DELETE` | `/invitations/{token}` | Cancel an invitation |
