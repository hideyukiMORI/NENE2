# 使用 NENE2 构建群组成员管理系统

本指南介绍如何构建群组系统，用户可以创建群组、邀请具有不同角色（owner/admin/member）的成员、管理成员资格并控制角色晋升。

**字段试验**：FT138
**NENE2 版本**：^1.5
**涵盖主题**：基于角色的成员资格，所有者自动加入，自主离开，MySQL 保留字陷阱（`groups`），漏洞评估

---

## 我们要构建的内容

- `POST /groups` — 创建群组（创建者成为所有者）
- `GET /groups/{groupId}/members` — 列出成员（仅成员可查）
- `POST /groups/{groupId}/members` — 添加成员（仅所有者/管理员，角色：member 或 admin）
- `DELETE /groups/{groupId}/members/{userId}` — 移除成员（所有者/管理员可移除他人；任何人可主动离开）
- `PUT /groups/{groupId}/members/{userId}/role` — 更改角色（仅所有者）

---

## 数据库结构——重要：避免使用 `groups` 作为表名

`groups` 是 **MySQL 的保留字**（用于 `GROUP BY`）。请改用 `user_groups`。

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

## 带能力方法的角色枚举

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

枚举上的能力方法将授权逻辑从处理程序中分离出去。

---

## 创建群组时所有者自动加入

创建群组时，所有者以 `owner` 角色自动添加为成员：

```php
public function createGroup(string $name, int $ownerId, string $now): int
{
    $this->executor->execute(
        'INSERT INTO user_groups (name, owner_id, created_at) VALUES (?, ?, ?)',
        [$name, $ownerId, $now],
    );

    $groupId = (int) $this->executor->lastInsertId();

    // 所有者以 'owner' 角色自动成为成员
    $this->executor->execute(
        'INSERT INTO memberships (group_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)',
        [$groupId, $ownerId, 'owner', $now],
    );

    return $groupId;
}
```

---

## 添加成员处理程序——角色校验

无法通过添加成员 API 分配 `owner` 角色。应用 `TokenScope::tryFrom()` 模式到 `MemberRole::tryFrom()`：

```php
$role = MemberRole::tryFrom($roleValue);

if ($role === null || $role === MemberRole::Owner) {
    return $this->responseFactory->create(['error' => 'role must be member or admin'], 422);
}
```

---

## 移除成员——主动离开与管理员移除

成员无需管理员权限即可主动离开群组。管理员可以移除他人。群主永远不能被移除：

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

## MySQL FK 清理——顺序很重要

在测试中重置 MySQL 时，使用 `FOREIGN_KEY_CHECKS = 0` 先删除依赖 FK 的表：

```php
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$this->pdo->exec('DROP TABLE IF EXISTS memberships');
$this->pdo->exec('DROP TABLE IF EXISTS user_groups');
$this->pdo->exec('DROP TABLE IF EXISTS users');
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
```

---

## 漏洞评估（FT138）

十二项漏洞测试验证：

| ID | 攻击 | 期望 | 结果 |
|----|------|------|------|
| VULN-A | IDOR：非成员读取成员列表 | 403 | 通过 |
| VULN-B | IDOR：非成员添加成员 | 403 | 通过 |
| VULN-C | 普通成员尝试添加他人 | 403 | 通过 |
| VULN-D | 管理员尝试设置 owner 角色 | 非 200 | 通过 |
| VULN-E | 成员尝试将自己晋升为 admin | 403 | 通过 |
| VULN-F | 移除群主 | 422 | 通过 |
| VULN-G | 创建时缺少 X-User-Id | 非 201 | 通过 |
| VULN-H | 非数字 X-User-Id | 非 200 | 通过 |
| VULN-I | 群组名称 SQL 注入 | 201（原样存储） | 通过 |
| VULN-J | 跨群组成员操作 | 403 | 通过 |
| VULN-K | 负数群组 ID | 404 | 通过 |
| VULN-L | 管理员不能更改角色 | 403 | 通过 |

12 项漏洞测试全部通过。未发现漏洞。

---

## 常见陷阱

| 陷阱 | 解决方案 |
|------|----------|
| 在 MySQL 中使用 `groups` 作为表名 | 使用 `user_groups`——`groups` 是 MySQL 保留字 |
| 所有者未自动添加到 memberships | 在 `createGroup()` 中 INSERT 所有者成员资格 |
| 管理员能够更改角色 | `canChangeRoles()` 仅对 `Owner` 返回 true |
| 允许通过添加成员 API 分配 `owner` 角色 | 拒绝 `role === MemberRole::Owner` → 422 |
| 非成员绕过 403（缺少操作者） | 检查 `findMembership(groupId, actorId) !== null` |
| MySQL DROP TABLE 因 FK 约束失败 | 在 DROP 前使用 `SET FOREIGN_KEY_CHECKS = 0` |
