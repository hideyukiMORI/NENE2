---
title: "Mass Assignment Defence"
category: security
tags: [mass-assignment, dto, whitelist, input-validation]
difficulty: beginner
related: [mass-assignment-defence, enforce-resource-ownership]
---

# Mass Assignment Defence

Mass assignment is a vulnerability where an attacker adds extra fields to a request body — such as `role=admin` or `is_active=false` — and the server persists them without intending to.

NENE2 has no `create($body)` magic method that would make this easy to trigger accidentally. Even so, the DTO whitelist pattern is the correct and explicit defence.

## The Vulnerability

```php
// ❌ Dangerous: $body passed directly to INSERT
$body = json_decode((string) $request->getBody(), true);

$this->executor->insert(
    'INSERT INTO users (name, email, role, is_active) VALUES (?, ?, ?, ?)',
    [$body['name'], $body['email'], $body['role'] ?? 'user', $body['is_active'] ?? 1],
);
```

An attacker sends:

```json
{
  "name": "Attacker",
  "email": "attacker@example.com",
  "role": "admin"
}
```

Because `$body['role']` is read from the request, the attacker receives `role=admin` in the database.

## The Defence: Explicit DTO Whitelist

Define a DTO that only contains the fields a user is allowed to supply:

```php
/**
 * Only name and email are accepted from user input.
 * role and is_active are set by server-side logic, never from the request.
 */
final readonly class CreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

In the controller, map only the allowed fields to the DTO:

```php
// ✅ Extra fields (role, is_active, id, created_at) are never read from $body
$input = new CreateUserInput(
    name:  trim((string) $body['name']),
    email: strtolower(trim((string) $body['email'])),
);

$user = $this->repo->create($input);
```

In the repository, use the DTO properties directly:

```php
public function create(CreateUserInput $input): User
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $id = $this->executor->insert(
        'INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
        [$input->name, $input->email, 'user', 1, $now], // role and is_active are hardcoded
    );
    // ...
}
```

Even if the attacker sends `role=admin`, `$input` only has `name` and `email` — the extra field never reaches the INSERT.

## Attack Scenarios Covered

| Field | Attack intent | Defence |
|-------|---------------|---------|
| `role=admin` | Privilege escalation | `role` is not in `CreateUserInput`; always set to `'user'` in repository |
| `is_active=false` | Create disabled account or lock a user | `is_active` not in DTO; always set to `1` |
| `id=9999` | Override primary key | `id` not in DTO; auto-assigned by SQLite |
| `created_at=2000-01-01` | Forge audit timestamp | `created_at` not in DTO; always set to current time |

## Response Field Control

The defence extends to the response: never return DB rows directly. Explicitly map what to include:

```php
return $this->json->create([
    'id'         => $user->id,
    'name'       => $user->name,
    'email'      => $user->email,
    'role'       => $user->role,
    'is_active'  => $user->isActive,
    'created_at' => $user->createdAt,
    // password_hash intentionally excluded
    // deleted_at intentionally excluded
], 201);
```

Test for the absence of sensitive fields:

```php
$this->assertArrayNotHasKey('password_hash', $data);
$this->assertArrayNotHasKey('deleted_at', $data);
```

## Trusted Internal Services

When an internal service needs to create an admin user (e.g., a provisioning service), use a separate DTO:

```php
final readonly class AdminCreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
        public string $role,   // allowed for internal callers only
        public bool $isActive,
    ) {}
}
```

Call this DTO only from code paths that have already verified the caller's identity (e.g., machine API key, internal service auth). Never expose a public HTTP endpoint that accepts `AdminCreateUserInput` directly.

## `create()` vs `createList()` for Responses

When returning a list, use `createList()` instead of `create()`:

```php
// ✅ Top-level JSON array
return $this->json->createList(array_map(fn (User $u) => [...], $users));

// ✅ Top-level JSON object
return $this->json->create(['id' => $user->id, ...], 201);
```

`create()` expects `array<string, mixed>` (an object). Passing `array_map()` output directly to `create()` causes a PHPStan level 8 type error because `array_map` returns a `list<T>`.

## Code Review Checklist

- [ ] Request body fields are mapped to a DTO before being passed to the repository
- [ ] DTO only contains fields the user is allowed to supply
- [ ] Server-controlled fields (`role`, `is_active`, timestamps, primary keys) are set in the repository, not read from `$body`
- [ ] Response explicitly lists returned fields; no wildcard `SELECT *` or direct row-to-JSON serialization
- [ ] Tests verify that extra request fields are ignored and do not affect the persisted value
