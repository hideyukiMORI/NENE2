# 操作指南：委托访问授权

> **FT 参考**：FT282（`NENE2-FT/grantlog`）——委托访问授权：有范围（read/write/admin）的限时资源访问，UNIQUE(grantor, grantee, resource) + CHECK(grantor != grantee)，IDOR 返回 404，软删除撤销，使用次数追踪，GrantScope.satisfies() 权限层级，23 个测试 / 71 个断言全部通过。
>
> 另在 FT176 中进行了验证——原始实现。

按用户粒度的限时可撤销访问委托——授权方（grantor）将对指定资源的有范围访问权限授予受权方（grantee），并设定时间窗口。

---

## 概述

委托访问授权允许一个用户（`grantor`）向另一个用户（`grantee`）授予对资源标识符的限时有范围访问权限。例如"将 document:42 以只读方式分享给用户 7，24 小时后过期，随时可撤销"。

关键特性：

- **多方参与**——授权方和受权方始终是不同的用户，自我授权将被拒绝。
- **状态机**——active → revoked（单向）；expired 状态由 `expires_at` 计算得出。
- **不透明资源**——`resource` 是自由格式字符串，服务器原样存储。
- **幂等唯一性**——每个 `(grantor_id, grantee_id, resource)` 组合只能有一条授权。
- **IDOR 安全**——所有所有权检查返回 404 而非 403，防止存在性枚举。

---

## 数据库结构

```sql
CREATE TABLE grants (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    grantor_id  INTEGER NOT NULL,
    grantee_id  INTEGER NOT NULL,
    resource    TEXT    NOT NULL,
    scope       TEXT    NOT NULL DEFAULT 'read',
    expires_at  TEXT    NOT NULL,
    revoked_at  TEXT,
    used_count  INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    UNIQUE (grantor_id, grantee_id, resource),
    CHECK (scope IN ('read', 'write', 'admin')),
    CHECK (grantor_id != grantee_id)
);
```

`CHECK (grantor_id != grantee_id)` 是纵深防御措施——自我授权还必须在应用层拒绝，以便返回清晰的错误响应。

---

## 领域层

### 带权限层级的 GrantScope 枚举

```php
enum GrantScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';

    public function satisfies(self $required): bool
    {
        $rank = [self::Read->value => 0, self::Write->value => 1, self::Admin->value => 2];
        return $rank[$this->value] >= $rank[$required->value];
    }
}
```

### Grant 实体——计算状态方法

```php
final readonly class Grant
{
    public function isExpired(string $now): bool  { return $this->expiresAt <= $now; }
    public function isRevoked(): bool             { return $this->revokedAt !== null; }
    public function isActive(string $now): bool   { return !$this->isExpired($now) && !$this->isRevoked(); }
}
```

先检查**已撤销**，再检查过期——两条路径都返回 403，但错误体不同，受权方可以了解访问失败的原因，同时不暴露系统内部信息。

---

## HTTP 端点

| 方法 | 路径 | 认证 | 用途 |
|------|------|------|------|
| `POST` | `/grants` | `X-User-Id`（授权方） | 创建授权 |
| `GET` | `/grants/issued` | `X-User-Id` | 列出调用者发出的授权 |
| `GET` | `/grants/received` | `X-User-Id` | 列出调用者收到的授权 |
| `DELETE` | `/grants/{id}` | `X-User-Id`（必须是授权方） | 撤销授权 |
| `POST` | `/grants/{id}/use` | `X-User-Id`（必须是受权方） | 使用授权 |

---

## 校验规则

| 字段 | 规则 |
|------|------|
| `grantee_id` | 必须是 **JSON 整数** 且 > 0；字符串 `"2"`、null、布尔值、浮点数均被拒绝 |
| `resource` | 非空字符串；≤ 500 个 UTF-8 字符；原样存储（不透明） |
| `scope` | 必须是 `read` / `write` / `admin` 之一 |
| `expires_at` | 有效的 ISO 8601 格式；必须是未来时间；距现在不超过 30 天 |
| 自我授权 | `grantee_id == grantor X-User-Id` → 422 |

### 严格整数字段解析

常见漏洞是隐式类型转换——将 `"2"`（JSON 字符串）当作 `2`（整数）接受。应使用显式类型检查：

```php
private function intField(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body)) {
        return null;
    }
    // is_int() 对 "2"、null、true、2.5 返回 false——仅对 PHP int 返回 true
    return is_int($body[$key]) ? $body[$key] : null;
}
```

注意：`2.0`（PHP float）经 `json_encode` 后与 `2`（int）无法区分——在单元测试中使用 `2.5` 来测试浮点数拒绝。

---

## 状态机

```
         revoke()
active ─────────────→ revoked   （第二次撤销返回 409）
  │
  │ expires_at ≤ now
  ↓
expired

revoked + expired → revoked 优先（先检查 revoked）
```

重复撤销必须返回 **409**，而不是静默接受。第二次调用后 `revoked_at` 时间戳不得改变。

---

## IDOR 防护模式

```php
// DELETE /grants/{id}
$grant = $this->repository->find($id);

// "未找到"和"不是你的授权"都返回 404
// 此处绝不返回 403——那会泄露存在性信息
if ($grant === null || $grant->grantorId !== $callerId) {
    return $this->responseFactory->create(['error' => "Grant #{$id} not found."], 404);
}
```

`POST /grants/{id}/use` 同理——如果调用者不是受权方，返回 404。

---

## 多方混淆防护

| 场景 | 预期行为 |
|------|---------|
| 授权方调用 `POST /grants/{id}/use`（使用自己的授权） | 404——授权方不是受权方 |
| 受权方调用 `DELETE /grants/{id}` | 404——受权方不是授权方 |
| 用户 3 对用户 1 和用户 2 之间的授权执行任何操作 | 404——IDOR |
| `X-User-Id: 0` 或 `X-User-Id: -1` | 401——非正数 ID 被拒绝 |
| 缺少 `X-User-Id` | 401 |

---

## 安全检查清单（ATK-01 到 ATK-12）

| # | 攻击向量 | 缓解措施 |
|---|---------|---------|
| ATK-01 | 过期授权（时钟边界） | `isExpired()` 比较；测试中在 DB 中设置过去的 `expires_at` |
| ATK-02 | 绕过已撤销授权状态 | 使用前进行 `isRevoked()` 检查 |
| ATK-03 | 自我授权（grantor == grantee） | 应用层 422 + DB `CHECK` |
| ATK-04 | 错误的受权方使用授权（IDOR） | 返回 404 而非 403 |
| ATK-05 | 非授权方撤销授权（IDOR） | 返回 404 而非 403；原授权保持有效 |
| ATK-06 | 创建时 `expires_at` 为过去时间 | `strtotime($expiresAt) <= strtotime($now)` → 422 |
| ATK-07 | `grantee_id` 类型混淆 | `is_int()` 严格检查；拒绝 `"2"`、`null`、`true`、`2.5` |
| ATK-08 | `resource` 中的路径遍历 | 不透明存储；无文件系统访问 |
| ATK-09 | `resource`/`scope` 中的 SQL 注入 | 参数化查询；scope 枚举拒绝注入值 |
| ATK-10 | `resource` 中的 Unicode/BIDI 字符 | 原样存储；同形字和 BIDI 视为不同资源 |
| ATK-11 | 重复撤销（状态机） | 第二次撤销返回 409；`revoked_at` 首次设置后不可变 |
| ATK-12 | 授权方以受权方身份使用自己的授权 | 404——角色严格执行 |

---

## 测试方法

- **ATK-01、ATK-02**：直接在 DB 中强制设置状态（`UPDATE grants SET expires_at/revoked_at`）来模拟时间穿越，无需睡眠等待。
- **ATK-07**：测试 `"2"`（字符串）、`null`、`true`、`2.5`（浮点数）——而非 `2.0`（PHP json_encode 后与 int 无法区分）。
- **ATK-10**：使用 `"\u{202E}"`（BIDI 覆盖）和西里尔文同形字来确认原样存储。
- **ATK-11**：在第二次撤销尝试后断言 DB 中 `revoked_at` 值未变。

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 无 `UNIQUE (grantor_id, grantee_id, resource)` | 同一对用户可创建重复授权；受权方对同一资源同时持有过期和有效授权 |
| 撤销时硬删除 | 丢失审计历史；无法得知访问何时被移除或使用了多少次 |
| 所有权检查返回 403 而非 404 | 向未授权调用者暴露授权是否存在；IDOR 枚举攻击面 |
| 无 `CHECK (grantor_id != grantee_id)` | 缺少纵深防御；应用层检查被绕过时自我授权可能漏过 |
| 接受自由格式 scope 字符串 | 拼写错误静默降级为 `read`；使用 `GrantScope::tryFrom()` 拒绝未知值 |
| scope 检查不使用 `satisfies()` 层级 | `write` 用户必须单独通过 `read` 检查；使用层级可一并检查所有低级权限 |
| `expires_at` 无最大 TTL 限制 | 授权方创建 100 年有效期的授权；实质上成为永久访问，无需定期审查 |
| 无 resource 长度限制 | 10MB 的 resource 字符串导致索引查找缓慢和内存分配压力 |
| 先检查过期再检查撤销 | 已撤销且已过期的授权应显示"已撤销"——状态机中撤销优先 |
| 客户端追踪 `used_count` | 客户端自报使用次数；服务端必须拥有该计数器 |
