# How-To: Password Hashing

Storing and verifying passwords securely using PHP's native `password_hash()` / `password_verify()` with NENE2.

---

## Quick start

```php
// Registration — hash before storing
$hash = password_hash($password, PASSWORD_ARGON2ID);
$user = $this->repo->create($email, $hash);

// Login — constant-time verification
if (!password_verify($inputPassword, $user->passwordHash)) {
    // return 401
}
```

---

## Algorithm: always use `PASSWORD_ARGON2ID`

`PASSWORD_DEFAULT` is still `bcrypt` as of PHP 8.4. Argon2id is memory-hard and resists GPU/ASIC attacks.

```php
// ❌ PASSWORD_DEFAULT = bcrypt — more vulnerable to GPU brute-force
$hash = password_hash($password, PASSWORD_DEFAULT);

// ✅ Argon2id — memory-hard, recommended for new projects
$hash = password_hash($password, PASSWORD_ARGON2ID);
```

Argon2id requires PHP 7.3+. NENE2 requires PHP 8.4, so it is always available.

---

## Detecting UNIQUE violations: `DatabaseConstraintException`

NENE2's `PdoDatabaseQueryExecutor` wraps all constraint violations (UNIQUE, FK, NOT NULL) into `DatabaseConstraintException` before rethrowing. Catching `\PDOException` directly does **not** work.

```php
use Nene2\Database\DatabaseConstraintException;

// ❌ Never reaches here — PDOException is already wrapped
catch (\PDOException $e) {
    if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) { ... }
}

// ✅ Catch the NENE2 wrapper
catch (DatabaseConstraintException) {
    throw new DuplicateEmailException($email);
}
```

`DatabaseConstraintException` is part of the stable public API (ADR 0009).

Full repository pattern:

```php
use Nene2\Database\DatabaseConstraintException;
use Nene2\Database\DatabaseQueryExecutorInterface;

final class UserRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor) {}

    /** @throws DuplicateEmailException */
    public function create(string $email, string $passwordHash): User
    {
        try {
            $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
            $id  = $this->executor->insert(
                'INSERT INTO users (email, password_hash, created_at) VALUES (?, ?, ?)',
                [$email, $passwordHash, $now],
            );

            return new User(id: $id, email: $email, passwordHash: $passwordHash, createdAt: $now);
        } catch (DatabaseConstraintException) {
            throw new DuplicateEmailException($email);
        }
    }
}
```

---

## User enumeration prevention (timing attack)

If you return 401 immediately when the email is not found, a timing difference reveals whether the email exists — not-found responses return instantly, while wrong-password responses take the full Argon2id compute time.

```php
// ❌ Timing leak — not-found is measurably faster
if ($user === null) {
    return $this->problems->create($request, 'invalid-credentials', ...);
}
if (!password_verify($password, $user->passwordHash)) {
    return $this->problems->create($request, 'invalid-credentials', ...);
}

// ✅ Always run password_verify — constant time regardless of whether user exists
$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

if (!password_verify($password, $hashToCheck) || $user === null) {
    return $this->problems->create($request, 'invalid-credentials', 'Invalid Credentials', 401,
        'The email or password is incorrect.');
}
```

The dummy hash **must** be a valid Argon2id format string starting with `$argon2id$`. If it is not, `password_verify()` short-circuits and returns `false` immediately, recreating the timing leak.

---

## `password_verify()` is algorithm-agnostic

`password_verify()` reads the hash prefix to determine the algorithm. You do not need to change the verification code when migrating from bcrypt to Argon2id.

```php
// Works on both bcrypt and Argon2id hashes
$result = password_verify($plaintext, $storedHash); // always correct
```

Use `password_needs_rehash()` on successful login to upgrade legacy hashes transparently:

```php
if (password_verify($password, $user->passwordHash)) {
    if (password_needs_rehash($user->passwordHash, PASSWORD_ARGON2ID)) {
        $newHash = password_hash($password, PASSWORD_ARGON2ID);
        $this->repo->updatePasswordHash($user->id, $newHash);
    }
    // proceed with authenticated user
}
```

---

## Never include `password_hash` in the response

`toArray()` or similar helpers may include every column. Explicitly list only the fields you intend to return.

```php
// ❌ May leak password_hash if $user has a toArray() method
return $this->json->create($user->toArray(), 201);

// ✅ Explicit field list — password_hash is never present
return $this->json->create([
    'id'         => $user->id,
    'email'      => $user->email,
    'created_at' => $user->createdAt,
], 201);
```

---

## `RouteRegistrar::register()` name conflict

NENE2's `RouteRegistrar` contract requires a public `register(Router $router)` method. Do **not** name a route handler `register()` — PHP will reject the duplicate method name.

```php
// ❌ Fatal error: Cannot redeclare RouteRegistrar::register()
$router->post('/register', $this->register(...));
private function register(...) { ... }

// ✅ Use a distinct handler name
$router->post('/register', $this->handleRegister(...));
private function handleRegister(...) { ... }
```

---

## Code review checklist

- [ ] `password_hash()` with `PASSWORD_ARGON2ID` is used (not MD5, SHA-1, bcrypt, or `PASSWORD_DEFAULT`)
- [ ] `password_verify()` is used for comparison (not `===`, `hash_equals()`, or custom comparison)
- [ ] `password_verify()` runs even when the user is not found (dummy hash pattern)
- [ ] `DatabaseConstraintException` is caught for duplicate email/username detection
- [ ] `password_hash` / `password` fields are excluded from all API responses
- [ ] Login returns 401 (not 404) for unknown email — never reveal whether the email exists
- [ ] Plaintext password is not written to logs
