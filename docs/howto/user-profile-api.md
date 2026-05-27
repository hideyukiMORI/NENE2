# How-to: User Profile API

> **FT reference**: FT275 (`NENE2-FT/profilelog`) — User profile: one-profile-per-user (UNIQUE user_id), email validated with FILTER_VALIDATE_EMAIL, field length limits (display_name 100 / bio 500 / avatar_url 2048), https-only avatar URL, DatabaseConstraintException → 409, ownership guard via X-User-Id, 32 tests PASS.

Demonstrates a 1:1 user-to-profile system: create a user (email unique), create/get/update their profile. Profile fields have enforced length limits and a URL security constraint.

---

## Schema

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

`user_id UNIQUE` enforces the one-profile-per-user invariant at the DB level.

---

## Routes

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/users` | Create user (email required, unique) |
| `POST` | `/users/{userId}/profile` | Create profile for user |
| `GET`  | `/users/{userId}/profile` | Get profile |
| `PUT`  | `/users/{userId}/profile` | Update profile (owner only) |

---

## Email validation

```php
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    return $this->responseFactory->create(['error' => 'valid email is required'], 422);
}
```

On duplicate email, `DatabaseConstraintException` is caught and mapped to 409:

```php
try {
    $userId = $this->repo->createUser($email, $now);
} catch (DatabaseConstraintException) {
    return $this->responseFactory->create(['error' => 'email already registered'], 409);
}
```

---

## Field limits (UserProfile value object)

```php
final readonly class UserProfile
{
    public const int MAX_BIO_LENGTH          = 500;
    public const int MAX_DISPLAY_NAME_LENGTH = 100;
    public const int MAX_AVATAR_URL_LENGTH   = 2048;
}
```

Length is checked with `mb_strlen()` (multi-byte safe):

```php
if (mb_strlen($displayName) > UserProfile::MAX_DISPLAY_NAME_LENGTH) {
    return [$displayName, $bio, $avatarUrl, 'display_name must not exceed 100 characters'];
}
```

---

## https-only avatar URL

```php
private function isValidAvatarUrl(string $url): bool
{
    if (mb_strlen($url) > UserProfile::MAX_AVATAR_URL_LENGTH) {
        return false;
    }
    // Only allow https to prevent javascript: and data: URI schemes
    if (!str_starts_with($url, 'https://')) {
        return false;
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
```

`str_starts_with('https://')` blocks `javascript:`, `data:`, and `http://` before `filter_var` runs.

---

## Ownership guard

Profile updates require `X-User-Id` to match the profile owner:

```php
$actorId = $this->resolveActorId($request); // from X-User-Id header

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| No email format validation | Invalid emails stored; downstream sends fail silently |
| No UNIQUE on `user_id` in profiles | Duplicate profiles possible; GET returns unpredictable row |
| Use `strlen()` for display_name limit | Multi-byte characters (emoji, CJK) counted wrong |
| Allow `http://` avatar URLs | Passive mixed-content and potential clickjacking surface |
| Allow `javascript:` or `data:` URIs | XSS if avatar URL is rendered as an `<a href>` or `<img src>` |
| Skip `DatabaseConstraintException` catch | UNIQUE violation becomes a 500 instead of 409 |
| Allow any user to update any profile | IDOR — always check actor = owner before write |
