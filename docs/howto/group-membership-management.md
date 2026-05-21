# How to Build Group Membership Management with NENE2

This guide walks through building a group system where users create groups, invite members with roles (owner/admin/member), manage membership, and control role promotion.

**Field Trial**: FT138  
**NENE2 version**: ^1.5  
**Covered topics**: role-based membership, owner auto-join, self-leave, MySQL reserved word pitfall (`groups`), vulnerability assessment

---

## What we're building

- `POST /groups` — create a group (creator becomes owner)
- `GET /groups/{groupId}/members` — list members (members only)
- `POST /groups/{groupId}/members` — add a member (owner/admin only, role: member or admin)
- `DELETE /groups/{groupId}/members/{userId}` — remove member (owner/admin can remove others; anyone can self-leave)
- `PUT /groups/{groupId}/members/{userId}/role` — change role (owner only)

---

## Database schema — IMPORTANT: avoid `groups` as table name

`groups` is a **reserved word in MySQL** (used in `GROUP BY`). Use `user_groups` instead.

```sql
-- SQLite
CREATE TABLE user_groups (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    owner_id   INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id)
);

CREATE TABLE memberships (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id  INTEGER NOT NULL,
    user_id   INTEGER NOT NULL,
    role      TEXT    NOT NULL DEFAULT 'member',
    joined_at TEXT    NOT NULL,
    UNIQUE (group_id, user_id),
    CHECK (role IN ('owner', 'admin', 'member')),
    FOREIGN KEY (group_id) REFERENCES user_groups(id),
    FOREIGN KEY (user_id)  REFERENCES users(id)
);
```

```sql
-- MySQL
CREATE TABLE user_groups (
    id         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    owner_id   INT          NOT NULL,
    created_at VARCHAR(32)  NOT NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE memberships (
    id        INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id  INT         NOT NULL,
    user_id   INT         NOT NULL,
    role      VARCHAR(16) NOT NULL DEFAULT 'member',
    joined_at VARCHAR(32) NOT NULL,
    UNIQUE KEY uq_group_user (group_id, user_id),
    CONSTRAINT chk_role CHECK (role IN ('owner', 'admin', 'member')),
    FOREIGN KEY (group_id) REFERENCES user_groups(id),
    FOREIGN KEY (user_id)  REFERENCES users(id)
) ENGINE=InnoDB;
```

---

## Role enum with capability methods

```php
enum MemberRole: string
{
    case Owner  = 'owner';
    case Admin  = 'admin';
    case Member = 'member';

    public function canManageMembers(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    public function canChangeRoles(): bool
    {
        return $this === self::Owner;
    }
}
```

Capability methods on the enum keep authorization logic out of the handlers.

---

## Owner auto-join on group creation

When a group is created, the owner is automatically added as a member with the `owner` role:

```php
public function createGroup(string $name, int $ownerId, string $now): int
{
    $this->executor->execute(
        'INSERT INTO user_groups (name, owner_id, created_at) VALUES (?, ?, ?)',
        [$name, $ownerId, $now],
    );

    $groupId = (int) $this->executor->lastInsertId();

    // Owner is automatically a member with 'owner' role
    $this->executor->execute(
        'INSERT INTO memberships (group_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)',
        [$groupId, $ownerId, 'owner', $now],
    );

    return $groupId;
}
```

---

## Add member handler — role validation

The `owner` role cannot be assigned via the add-member API. `TokenScope::tryFrom()` pattern applied to `MemberRole::tryFrom()`:

```php
$role = MemberRole::tryFrom($roleValue);

if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

---

## Remove member — self-leave and admin remove

A member can leave their own group (self-leave) without admin rights. Admins can remove others. The owner can never be removed:

```php
$isSelfLeave = $actorId === $userId;

if (!$isSelfLeave && !$actorRole->canManageMembers()) {
    return $this->responseFactory->create(['error' => 'only owner or admin can remove members'], 403);
}

$targetRole = MemberRole::tryFrom($targetMembership['role']) ?? MemberRole::Member;

if ($targetRole === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'cannot remove the group owner'], 422);
}
```

---

## MySQL FK teardown — order matters

When resetting MySQL in tests, drop FK-dependent tables first with `FOREIGN_KEY_CHECKS = 0`:

```php
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$this->pdo->exec('DROP TABLE IF EXISTS memberships');
$this->pdo->exec('DROP TABLE IF EXISTS user_groups');
$this->pdo->exec('DROP TABLE IF EXISTS users');
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
```

---

## Vulnerability assessment (FT138)

Twelve vulnerability tests verify:

| ID | Attack | Expected | Result |
|----|--------|----------|--------|
| VULN-A | IDOR: non-member reads member list | 403 | Pass |
| VULN-B | IDOR: non-member adds a member | 403 | Pass |
| VULN-C | Regular member tries to add someone | 403 | Pass |
| VULN-D | Admin tries to set owner role | not 200 | Pass |
| VULN-E | Member tries to promote self to admin | 403 | Pass |
| VULN-F | Remove group owner | 422 | Pass |
| VULN-G | Missing X-User-Id on create | not 201 | Pass |
| VULN-H | Non-numeric X-User-Id | not 200 | Pass |
| VULN-I | SQL injection in group name | 201 (verbatim) | Pass |
| VULN-J | Cross-group member operation | 403 | Pass |
| VULN-K | Negative group ID | 404 | Pass |
| VULN-L | Admin cannot change roles | 403 | Pass |

All 12 vulnerability tests pass. No vulnerabilities found.

---

## Common pitfalls

| Pitfall | Fix |
|---------|-----|
| Using `groups` as table name in MySQL | Use `user_groups` — `groups` is a MySQL reserved word |
| Owner not auto-added to memberships | INSERT owner membership in `createGroup()` |
| Admin being able to change roles | `canChangeRoles()` returns true only for `Owner` |
| Allowing `owner` role via add-member API | Reject `role === MemberRole::Owner` → 422 |
| Non-member bypassing 403 via missing actor | Check `findMembership(groupId, actorId) !== null` |
| MySQL DROP TABLE fails with FK constraints | `SET FOREIGN_KEY_CHECKS = 0` before DROP |
