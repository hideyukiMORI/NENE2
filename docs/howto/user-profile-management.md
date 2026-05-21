# User Profile Management

Store and update user-facing profile data: display name, bio, and avatar URL. Profile creation is separate from user creation — users exist first, then a profile is created once and updated in place.

## Overview

A profile management API involves:
- **Create user** — email-based user registration (one profile per user)
- **Create profile** — initial profile setup (idempotent-resistant: 409 if already exists)
- **Get profile** — retrieve current profile data
- **Update profile** — replace profile fields (ownership enforced)

## Database Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE profiles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL UNIQUE,
    display_name TEXT    NOT NULL DEFAULT '',
    bio          TEXT    NOT NULL DEFAULT '',
    avatar_url   TEXT    NOT NULL DEFAULT '',
    updated_at   TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE` on `user_id` enforces one profile per user at the DB level.

## Handling Duplicate Email

Catch `DatabaseConstraintException` to return 409 instead of leaking a 500:

```php
try {
    $userId = $this->repo->createUser($email, $now);
} catch (DatabaseConstraintException) {
    return $this->responseFactory->create(['error' => 'email already registered'], 409);
}
```

Without this catch, a duplicate email causes an unhandled exception that exposes internal error details to the client.

## Avatar URL Validation

Only allow `https://` URLs to prevent `javascript:`, `data:`, `file://`, and `http://` schemes:

```php
private function isValidAvatarUrl(string $url): bool
{
    if (mb_strlen($url) > UserProfile::MAX_AVATAR_URL_LENGTH) {
        return false;
    }

    // Only https — blocks javascript:, data:, file://, ftp://, http://
    if (!str_starts_with($url, 'https://')) {
        return false;
    }

    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
```

Empty string is allowed (no avatar set). The `MAX_AVATAR_URL_LENGTH = 2048` limit prevents storage abuse.

## Field Length Limits

Define limits as constants on the value object for a single source of truth:

```php
final readonly class UserProfile
{
    public const int MAX_BIO_LENGTH          = 500;
    public const int MAX_DISPLAY_NAME_LENGTH = 100;
    public const int MAX_AVATAR_URL_LENGTH   = 2048;
    ...
}
```

Use `mb_strlen()` not `strlen()` for multibyte (UTF-8) correctness.

## Ownership Check

The `PUT /users/{userId}/profile` endpoint uses an `X-User-Id` header to identify the requesting actor. In production, replace this with a JWT claim:

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');
    return is_numeric($header) ? (int) $header : 0;
}

// In the handler:
if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

Non-numeric or missing header resolves to `0`, which never matches a real user ID → 403.

## Duplicate Profile Prevention

Check for existing profile before inserting and return 409:

```php
if ($this->repo->findByUserId($userId) !== null) {
    return $this->responseFactory->create(['error' => 'profile already exists'], 409);
}
```

This prevents a second `POST /users/{userId}/profile` from overwriting an existing profile silently.

## Security Properties

| Property | Implementation |
|---|---|
| Duplicate email | `DatabaseConstraintException` caught → 409 (no stack trace leaked) |
| avatar_url scheme | `str_starts_with('https://')` blocks all non-https schemes |
| avatar_url length | `MAX_AVATAR_URL_LENGTH = 2048` |
| bio length | `MAX_BIO_LENGTH = 500` with `mb_strlen()` |
| Ownership | `X-User-Id` header (replace with JWT claim in production) |
| One profile per user | `UNIQUE (user_id)` DB constraint + 409 check in handler |

## Route Summary

| Method | Path | Description |
|---|---|---|
| `POST` | `/users` | Register a user (email, 409 on duplicate) |
| `POST` | `/users/{userId}/profile` | Create profile (409 if already exists) |
| `GET` | `/users/{userId}/profile` | Get profile |
| `PUT` | `/users/{userId}/profile` | Update profile (requires `X-User-Id` header) |
