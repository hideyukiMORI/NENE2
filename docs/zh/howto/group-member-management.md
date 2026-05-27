# 操作指南：群组成员管理

> **FT 参考**：FT291（`NENE2-FT/grouplog`）——群组成员资格：MemberRole 枚举（owner/admin/member），UNIQUE(group_id, user_id)，群主不可被移除防护，跨群组 IDOR 防护，`canManageMembers()`/`canChangeRoles()` 角色层级，VULN-A 至 VULN-L 全部通过，38 个测试 / 101 个断言全部通过。

本指南展示如何构建基于角色的群组成员管理系统——所有者、管理员和成员具有不同级别的权限。

## 数据库结构

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

`UNIQUE(group_id, user_id)` 防止重复成员资格。`CHECK(role IN ...)` 在 DB 层面阻止无效角色。

## 端点

| 方法 | 路径 | 认证 | 描述 |
|------|------|------|------|
| `POST` | `/groups` | `X-User-Id` | 创建群组（操作者成为所有者） |
| `GET` | `/groups/{groupId}/members` | `X-User-Id`（成员） | 列出成员 |
| `POST` | `/groups/{groupId}/members` | `X-User-Id`（所有者/管理员） | 添加成员 |
| `DELETE` | `/groups/{groupId}/members/{userId}` | `X-User-Id` | 移除成员 |
| `PUT` | `/groups/{groupId}/members/{userId}/role` | `X-User-Id`（所有者） | 更改角色 |

## MemberRole 枚举

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

角色能力：
- **Owner（所有者）**：可以添加/移除成员、更改角色，不可被移除
- **Admin（管理员）**：可以添加/移除成员，不可更改角色
- **Member（成员）**：只能离开（移除自己）

## 操作者解析

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');
    return is_numeric($header) ? (int) $header : 0;
}
```

非数字请求头返回 0（无效）。每个特权操作在执行前都会对 DB 验证操作者。

## 所有操作前进行成员资格检查

```php
$actorMembership = $actorId > 0 ? $this->repo->findMembership($groupId, $actorId) : null;

if ($actorMembership === null) {
    return $this->responseFactory->create(['error' => 'not a member'], 403);
}
```

非成员在所有群组操作（包括列出成员）上收到 403——防止 IDOR。

## 添加成员——角色层级

```php
$actorRole = MemberRole::tryFrom($actorMembership['role']) ?? MemberRole::Member;

if (!$actorRole->canManageMembers()) {
    return $this->responseFactory->create(['error' => 'only owner or admin can add members'], 403);
}

// 无法通过添加成员端点分配 'owner' 角色
$role = MemberRole::tryFrom($roleValue);
if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

`owner` 角色无法通过 API 分配——仅在创建群组时设置。

## 群主不可被移除

```php
$targetRole = MemberRole::tryFrom($targetMembership['role']) ?? MemberRole::Member;

if ($targetRole === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'cannot remove the group owner'], 422);
}
```

群主受到移除保护。所有权转让需要专用端点。

## 主动离开 vs 管理员移除

```php
$isSelfLeave = $actorId === $userId;

if (!$isSelfLeave && !$actorRole->canManageMembers()) {
    return $this->responseFactory->create(['error' => 'only owner or admin can remove members'], 403);
}
```

成员无需管理员权限即可主动离开（移除自己）。移除其他用户需要 `canManageMembers()`。

## 更改角色——仅所有者

```php
if (!$actorRole->canChangeRoles()) {
    return $this->responseFactory->create(['error' => 'only owner can change roles'], 403);
}

$role = MemberRole::tryFrom($roleValue);
if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

只有所有者可以晋升/降级成员。`owner` 角色不可分配（防止静默所有权盗用）。

---

## 漏洞评估

### V-01 — IDOR：非成员读取成员列表 ✅ SAFE

**风险**：非成员调用 `GET /groups/{id}/members` 枚举用户。
**结论**：SAFE — `findMembership(groupId, actorId) === null` → 在返回任何数据前返回 403。

---

### V-02 — IDOR：非成员向群组添加用户 ✅ SAFE

**风险**：非成员调用 `POST /groups/{id}/members` 注入用户。
**结论**：SAFE — 相同的成员资格检查；非成员 → 403。

---

### V-03 — 权限提升：普通成员添加其他成员 ✅ SAFE

**风险**：普通成员（`role = 'member'`）尝试添加新用户。
**结论**：SAFE — `canManageMembers()` 对 `Member` 返回 false → 403。

---

### V-04 — 权限提升：管理员晋升为所有者 ✅ SAFE

**风险**：管理员通过添加成员或更改角色端点尝试分配 `role = 'owner'`。
**结论**：SAFE — 两个端点都拒绝 `MemberRole::Owner` 作为可分配角色 → 422。

---

### V-05 — 权限提升：成员自我晋升 ✅ SAFE

**风险**：普通成员调用 `PUT /groups/{id}/members/{self}/role`。
**结论**：SAFE — `canChangeRoles()` 对 `Member` 和 `Admin` 返回 false → 403。

---

### V-06 — 移除群主 ✅ SAFE

**风险**：管理员尝试移除群主。
**结论**：SAFE — `if ($targetRole === MemberRole::Owner)` → 422。

---

### V-07 — 创建群组时缺少 X-User-Id ✅ SAFE

**风险**：没有 `X-User-Id` 的请求创建无有效所有者的群组。
**结论**：SAFE — `resolveActorId()` 对缺少/无效请求头返回 0 → `findUserById(0)` 返回 null → 404。

---

### V-08 — 非数字 X-User-Id ✅ SAFE

**风险**：请求头 `X-User-Id: admin` 绕过数字操作者校验。
**结论**：SAFE — `is_numeric($header)` 对非数字字符串返回 false → 返回 0 → 被拒绝。

---

### V-09 — 群组名称 SQL 注入 ✅ SAFE

**风险**：群组名称 `'; DROP TABLE user_groups; --` 删除数据。
**结论**：SAFE — 所有查询使用参数化语句。注入字符串作为群组名称原样存储，不执行。

---

### V-10 — 跨群组成员操作（IDOR） ✅ SAFE

**风险**：群组 A 的所有者尝试从群组 B 移除成员。
**结论**：SAFE — `findMembership(groupId, actorId)` 检查*目标*群组中的成员资格。群组 A 的所有者在群组 B 中没有成员资格 → 403。

---

### V-11 — 负数群组 ID ✅ SAFE

**风险**：`GET /groups/-1/members` 导致 DB 错误或意外行为。
**结论**：SAFE — `is_numeric($params['groupId']) ? (int)$params['groupId'] : 0` 接受 `-1` 作为数字，但 `findGroupById(-1)` 返回 null → 404。

---

### V-12 — 管理员不能更改角色 ✅ SAFE

**风险**：管理员调用 `PUT /groups/{id}/members/{userId}/role` 晋升用户。
**结论**：SAFE — `canChangeRoles()` 仅限所有者 → 管理员收到 403。

---

### VULN 汇总

| ID | 漏洞 | 结论 |
|----|------|------|
| V-01 | IDOR：非成员读取成员列表 | ✅ SAFE |
| V-02 | IDOR：非成员添加成员 | ✅ SAFE |
| V-03 | 权限提升：成员添加成员 | ✅ SAFE |
| V-04 | 权限提升：管理员 → 所有者 | ✅ SAFE |
| V-05 | 权限提升：成员自我晋升 | ✅ SAFE |
| V-06 | 群主被移除 | ✅ SAFE |
| V-07 | 创建时缺少 X-User-Id | ✅ SAFE |
| V-08 | 非数字 X-User-Id | ✅ SAFE |
| V-09 | 群组名称 SQL 注入 | ✅ SAFE |
| V-10 | 跨群组 IDOR（其他群组的所有者） | ✅ SAFE |
| V-11 | 负数群组 ID | ✅ SAFE |
| V-12 | 管理员不能更改角色 | ✅ SAFE |

**12 SAFE，0 EXPOSED**
每次操作前的成员资格检查、`canManageMembers()`/`canChangeRoles()` 角色层级以及群主移除防护，共同防御了所有权限提升和 IDOR 攻击向量。

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 列出成员前无成员资格检查 | 非成员枚举所有群组用户（IDOR） |
| 允许通过添加成员分配 `owner` 角色 | 任何管理员都能静默地获取所有权 |
| 允许通过更改角色分配 `owner` 角色 | 同上——一次请求即可盗取所有权 |
| 跳过 `canManageMembers()` 检查 | 普通成员添加/移除任何人 |
| 允许移除群主 | 群组失去其管理用户 |
| 无 `UNIQUE(group_id, user_id)` | 同一用户被添加两次；重复成员资格记录 |
| 仅对 X-User-Id 进行 `is_numeric()` 检查 | `"1.5"` 通过 `is_numeric`；使用 `(int)` 转换 + 对 DB 进行验证 |
| 检查操作者自己群组（而非目标群组）的成员资格 | 跨群组 IDOR：群组 A 的所有者修改群组 B |
| 允许管理员更改角色 | 管理员将自己晋升为所有者；角色层级绕过 |
