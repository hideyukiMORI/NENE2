# Account Lockout (Brute-Force Protection)

Protect login endpoints against brute-force attacks by locking an account after a configurable number of failed attempts.

## Overview

Account lockout tracks failed login attempts per email address and sets a `locked_until` timestamp when the failure threshold is exceeded. The lock is enforced on every login attempt — even a correct password is rejected while the account is locked. The lock expires automatically after a cooldown period.

## Database Schema

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE account_states (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    email        TEXT    NOT NULL UNIQUE,
    failed_count INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT,
    updated_at   TEXT    NOT NULL
);
```

`account_states` tracks per-account failure history. `locked_until` is null for unlocked accounts.

## Constants

```php
public const int MAX_ATTEMPTS    = 5;   // failures before lockout
public const int LOCKOUT_MINUTES = 15;  // lockout duration
```

## Login Flow

```php
// 1. Check lockout before password verification
$state = $this->repo->findOrCreateAccountState($email, $now);
if ($state->isLocked($now)) {
    return 423; // Locked
}

// 2. Verify credentials
$user = $this->repo->findUserByEmail($email);
if ($user === null || !$user->verifyPassword($pass)) {
    if ($user !== null) {
        $this->repo->recordFailure($email, $now);
    }
    return 401; // Unauthorized
}

// 3. Success — reset counter
$this->repo->resetState($email, $now);
return 200;
```

The lockout check happens **before** password verification. The lockout state is written only for **existing users** — unknown emails return 401 without creating an `account_state` row (prevents storage exhaustion).

## Lockout Check

```php
public function isLocked(string $now): bool
{
    return $this->lockedUntil !== null && $now < $this->lockedUntil;
}
```

`$now` is a `Y-m-d H:i:s` string. Lexicographic comparison works correctly for ISO 8601 datetime strings.

## Recording Failure

```php
public function recordFailure(string $email, string $now): AccountState
{
    $state    = $this->findOrCreateAccountState($email, $now);
    $newCount = $state->failedCount + 1;

    $lockedUntil = null;
    if ($newCount >= AccountState::MAX_ATTEMPTS) {
        $lockedUntil = date('Y-m-d H:i:s', strtotime($now) + AccountState::LOCKOUT_MINUTES * 60);
    }

    $this->executor->execute(
        'UPDATE account_states SET failed_count = ?, locked_until = ?, updated_at = ? WHERE email = ?',
        [$newCount, $lockedUntil, $now, $email],
    );
    ...
}
```

When `failed_count` reaches `MAX_ATTEMPTS`, `locked_until` is set to `now + LOCKOUT_MINUTES * 60` seconds.

## Reset on Success

```php
$this->executor->execute(
    'UPDATE account_states SET failed_count = 0, locked_until = NULL, updated_at = ? WHERE email = ?',
    [$now, $email],
);
```

Successful authentication resets both `failed_count` and `locked_until`. A user who succeeds before lockout gets a fresh failure counter.

## User Enumeration Prevention

Return the same HTTP status (401) for both wrong password and unknown email:

```php
if ($user === null || !$user->verifyPassword($pass)) {
    if ($user !== null) {
        $this->repo->recordFailure($email, $now);
    }
    return 401; // same status regardless
}
```

An attacker cannot distinguish "no account" from "wrong password" via the HTTP response.

## MySQL Schema

For MySQL, use `INT AUTO_INCREMENT` and `DATETIME`:

```sql
CREATE TABLE IF NOT EXISTS users (
    id            INT          NOT NULL AUTO_INCREMENT,
    email         VARCHAR(255) NOT NULL,
    password_hash TEXT         NOT NULL,
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_states (
    id           INT          NOT NULL AUTO_INCREMENT,
    email        VARCHAR(255) NOT NULL,
    failed_count INT          NOT NULL DEFAULT 0,
    locked_until DATETIME     NULL,
    updated_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

The `Y-m-d H:i:s` datetime format works for both SQLite (TEXT comparison) and MySQL (DATETIME column).

## MySQL Integration Test

Add a `MysqlLockoutTest.php` that skips unless `MYSQL_HOST` is set:

```php
protected function setUp(): void
{
    $host = (string) (getenv('MYSQL_HOST') ?: '');
    if ($host === '') {
        self::markTestSkipped('MYSQL_HOST not set — skipping MySQL integration tests');
    }
    // Drop + recreate tables for test isolation
    $this->pdo->exec('DROP TABLE IF EXISTS account_states');
    $this->pdo->exec('DROP TABLE IF EXISTS users');
    $this->pdo->exec($mysqlSchema);
    ...
}
```

Run against the shared FT MySQL container (port 3308, persistent volume):

```bash
docker compose -f ../NENE2-FT/docker-compose.yml up -d mysql
```

Then run the integration tests with environment variables:

```bash
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3308 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass \
  php8.4 vendor/bin/phpunit --filter Mysql
```

Without `MYSQL_HOST`, the MySQL tests are automatically skipped.

## Security Properties

| Property | Implementation |
|---|---|
| Lockout threshold | 5 failed attempts |
| Lockout duration | 15 minutes |
| Correct password during lockout | Blocked (423) |
| User enumeration | Same 401 for unknown email and wrong password |
| Lockout scope | Per email address, not per IP |
| Lockout reset | Automatic on successful login |
| Password hashing | Argon2id |
| Long email input | Rejected at 256+ characters (422) |
| SQL injection | Parameterized queries prevent injection |

## Design Trade-off: Lockout DoS

Because lockout is per-email (not per-IP), an attacker who knows a user's email can lock them out by submitting 5 wrong passwords. This is an inherent tension between brute-force protection and availability.

Mitigations (not implemented here, but available):
- **Progressive delays** instead of hard lockout
- **CAPTCHA** after N failures
- **Notification email** when lockout is triggered
- **Admin unlock endpoint**

For most applications, the trade-off favors brute-force protection. The lockout self-expires after 15 minutes.

## Route Summary

| Method | Path | Description |
|---|---|---|
| `POST` | `/users` | Create user (seed/registration) |
| `POST` | `/auth/login` | Login attempt (200/401/423) |
| `GET` | `/auth/status/{email}` | Check lockout status |
