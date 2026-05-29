---
title: "How-to: Group Member Management"
category: product
tags: [group, membership, rbac, role-hierarchy, idor]
difficulty: advanced
related: [group-membership-management, rbac, enforce-resource-ownership]
ft: FT291
---

# How-to: Group Member Management

> **FT reference**: FT291 (`NENE2-FT/grouplog`) — Group membership: MemberRole enum (owner/admin/member), UNIQUE(group_id, user_id), owner-cannot-be-removed guard, cross-group IDOR prevention, canManageMembers()/canChangeRoles() role hierarchy, VULN-A~L all SAFE, 38 tests / 101 assertions PASS.

This guide shows how to build a group management system with role-based membership control — owners, admins, and members with graduated permissions.

## Schema

```sql
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

`UNIQUE(group_id, user_id)` prevents duplicate memberships. `CHECK(role IN ...)` blocks invalid roles at DB level.

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/groups` | `X-User-Id` | Create group (actor becomes owner) |
| `GET` | `/groups/{groupId}/members` | `X-User-Id` (member) | List members |
| `POST` | `/groups/{groupId}/members` | `X-User-Id` (owner/admin) | Add member |
| `DELETE` | `/groups/{groupId}/members/{userId}` | `X-User-Id` | Remove member |
| `PUT` | `/groups/{groupId}/members/{userId}/role` | `X-User-Id` (owner) | Change role |

## MemberRole Enum

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

Role capabilities:
- **Owner**: can add/remove members, change roles, cannot be removed
- **Admin**: can add/remove members, cannot change roles
- **Member**: can only leave (remove self)

## Actor Resolution

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');
    return is_numeric($header) ? (int) $header : 0;
}
```

Non-numeric headers return 0 (invalid). Every privileged operation validates the actor against the DB before proceeding.

## Membership Check Before Any Operation

```php
$actorMembership = $actorId > 0 ? $this->repo->findMembership($groupId, $actorId) : null;

if ($actorMembership === null) {
    return $this->responseFactory->create(['error' => 'not a member'], 403);
}
```

Non-members get 403 on all group operations — including listing members (IDOR prevention).

## Adding Members — Role Hierarchy

```php
$actorRole = MemberRole::tryFrom($actorMembership['role']) ?? MemberRole::Member;

if (!$actorRole->canManageMembers()) {
    return $this->responseFactory->create(['error' => 'only owner or admin can add members'], 403);
}

// Cannot assign 'owner' via add-member endpoint
$role = MemberRole::tryFrom($roleValue);
if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

`owner` role cannot be assigned via the API — it is set only at group creation.

## Owner Cannot Be Removed

```php
$targetRole = MemberRole::tryFrom($targetMembership['role']) ?? MemberRole::Member;

if ($targetRole === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'cannot remove the group owner'], 422);
}
```

The owner is protected from removal. Ownership transfer would require a dedicated endpoint.

## Self-Leave vs. Admin Remove

```php
$isSelfLeave = $actorId === $userId;

if (!$isSelfLeave && !$actorRole->canManageMembers()) {
    return $this->responseFactory->create(['error' => 'only owner or admin can remove members'], 403);
}
```

Members can remove themselves (self-leave) without admin rights. Removing another user requires `canManageMembers()`.

## Role Change — Owner Only

```php
if (!$actorRole->canChangeRoles()) {
    return $this->responseFactory->create(['error' => 'only owner can change roles'], 403);
}

$role = MemberRole::tryFrom($roleValue);
if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

Only the owner can promote/demote members. The `owner` role cannot be assigned (preventing silent ownership theft).

---

## Vulnerability Assessment

### V-01 — IDOR: Non-member reads member list ✅ SAFE

**Risk**: Non-member calls `GET /groups/{id}/members` to enumerate users.
**Finding**: SAFE — `findMembership(groupId, actorId) === null` → 403 before returning any data.

---

### V-02 — IDOR: Non-member adds someone to a group ✅ SAFE

**Risk**: Non-member calls `POST /groups/{id}/members` to inject users.
**Finding**: SAFE — same membership check; non-member → 403.

---

### V-03 — Privilege Escalation: regular member adds another member ✅ SAFE

**Risk**: Regular member (`role = 'member'`) tries to add a new user.
**Finding**: SAFE — `canManageMembers()` returns false for `Member` → 403.

---

### V-04 — Privilege Escalation: admin promotes to owner ✅ SAFE

**Risk**: Admin tries to assign `role = 'owner'` via add-member or change-role endpoints.
**Finding**: SAFE — both endpoints reject `MemberRole::Owner` as a valid assignable role → 422.

---

### V-05 — Privilege Escalation: member promotes self ✅ SAFE

**Risk**: Regular member calls `PUT /groups/{id}/members/{self}/role`.
**Finding**: SAFE — `canChangeRoles()` returns false for `Member` and `Admin` → 403.

---

### V-06 — Owner Removal ✅ SAFE

**Risk**: Admin tries to remove the group owner.
**Finding**: SAFE — `if ($targetRole === MemberRole::Owner)` → 422.

---

### V-07 — Missing X-User-Id on group creation ✅ SAFE

**Risk**: Request without `X-User-Id` creates a group with no valid owner.
**Finding**: SAFE — `resolveActorId()` returns 0 for missing/invalid header → `findUserById(0)` returns null → 404.

---

### V-08 — Non-numeric X-User-Id ✅ SAFE

**Risk**: Header `X-User-Id: admin` bypasses numeric actor validation.
**Finding**: SAFE — `is_numeric($header)` returns false for non-numeric strings → returns 0 → rejected.

---

### V-09 — SQL injection in group name ✅ SAFE

**Risk**: Group name `'; DROP TABLE user_groups; --` deletes data.
**Finding**: SAFE — all queries use parameterized statements. Injection string is stored verbatim as the group name without execution.

---

### V-10 — Cross-group member operation (IDOR) ✅ SAFE

**Risk**: Owner of group A tries to remove a member from group B.
**Finding**: SAFE — `findMembership(groupId, actorId)` checks membership in the *target* group. Owner of group A has no membership in group B → 403.

---

### V-11 — Negative group ID ✅ SAFE

**Risk**: `GET /groups/-1/members` causes DB error or unexpected behavior.
**Finding**: SAFE — `is_numeric($params['groupId']) ? (int)$params['groupId'] : 0` accepts `-1` as numeric, but `findGroupById(-1)` returns null → 404.

---

### V-12 — Admin cannot change roles ✅ SAFE

**Risk**: Admin calls `PUT /groups/{id}/members/{userId}/role` to promote users.
**Finding**: SAFE — `canChangeRoles()` is owner-only → admin gets 403.

---

### VULN Summary

| ID | Vulnerability | Finding |
|----|---------------|---------|
| V-01 | IDOR: non-member reads member list | ✅ SAFE |
| V-02 | IDOR: non-member adds member | ✅ SAFE |
| V-03 | Privilege escalation: member adds member | ✅ SAFE |
| V-04 | Privilege escalation: admin → owner | ✅ SAFE |
| V-05 | Privilege escalation: member promotes self | ✅ SAFE |
| V-06 | Owner removal | ✅ SAFE |
| V-07 | Missing X-User-Id on create | ✅ SAFE |
| V-08 | Non-numeric X-User-Id | ✅ SAFE |
| V-09 | SQL injection in group name | ✅ SAFE |
| V-10 | Cross-group IDOR (owner of other group) | ✅ SAFE |
| V-11 | Negative group ID | ✅ SAFE |
| V-12 | Admin cannot change roles | ✅ SAFE |

**12 SAFE, 0 EXPOSED**
Membership check before every operation, `canManageMembers()`/`canChangeRoles()` role hierarchy, and owner-removal guard prevent all privilege escalation and IDOR vectors.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| No membership check before listing members | Non-members enumerate all group users (IDOR) |
| Allow `owner` role assignment via add-member | Any admin can silently take ownership |
| Allow `owner` role assignment via change-role | Same — ownership theft with one request |
| Skip `canManageMembers()` check | Regular members add/remove anyone |
| Allow owner removal | Group loses its governing user |
| No `UNIQUE(group_id, user_id)` | Same user added twice; duplicate membership records |
| `is_numeric()` check only for X-User-Id | `"1.5"` passes `is_numeric`; use `(int)` cast + validate against DB |
| Check membership in actor's own group (not target group) | Cross-group IDOR: owner of group A modifies group B |
| Allow admin to change roles | Admin self-promotes to owner; role hierarchy bypass |
