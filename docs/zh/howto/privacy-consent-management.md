# 如何构建隐私同意管理

> **模式由 FT189 consentlog 验证**——GDPR 风格的同意追踪，带不可变历史、IDOR 防护和用户枚举抵抗。VULN-A~L 全部通过。

---

## 本文涵盖内容

隐私同意管理流程：

1. **授予同意**——用户授予对命名用途的同意
2. **撤回同意**——用户撤回同意
3. **列出同意**——所有用途的当前同意状态
4. **历史记录**——每个用途的不可变仅追加审计日志

安全保证：

| 关注点 | 技术 |
|---|---|
| IDOR——访问其他用户的同意 | 所有查询限定 `WHERE user_id = :user_id` |
| 批量赋值（granted 字段） | `granted` 由服务器控制；请求体无法覆盖 |
| purpose 中的 SQL 注入 | `ctype_alnum()`——仅字母数字 |
| purpose 中的 ReDoS | `ctype_alnum()` O(n)——无正则表达式 |
| 类型混淆 | `ctype_alnum()` 之前先 `is_string()` |
| 用户枚举 | 未知用户返回空数组，而非 404 |
| grant/withdraw 上的竞态条件 | `UNIQUE(user_id, purpose)` 上的 UPSERT 原子性 |
| 同意重放 | 历史记录仅追加；每次变更是新条目 |

---

## 数据库结构

```sql
CREATE TABLE consents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    purpose    TEXT    NOT NULL,  -- 字母数字 slug：'marketing'、'analytics' 等
    granted    INTEGER NOT NULL DEFAULT 1,  -- 1=已授予，0=已撤回
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(user_id, purpose)
);

CREATE TABLE consent_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    purpose    TEXT    NOT NULL,
    granted    INTEGER NOT NULL,   -- 1=已授予，0=已撤回
    created_at TEXT    NOT NULL    -- 此次变更发生的时间
);
```

`UNIQUE(user_id, purpose)` 支持原子 upsert。`consent_history` 仅追加——永不更新。

---

## API

| 方法 | 路径 | 请求头 | 描述 |
|---|---|---|---|
| `POST` | `/consents` | `X-User-Id` | 授予同意（201） |
| `DELETE` | `/consents/{purpose}` | `X-User-Id` | 撤回同意（200） |
| `GET` | `/consents` | `X-User-Id` | 列出当前同意 |
| `GET` | `/consents/{purpose}/history` | `X-User-Id` | 审计历史（仅追加） |

---

## 核心模式：幂等 UPSERT

```php
// 授予——幂等：重复授予已授予的用途是安全的
INSERT INTO consents (user_id, purpose, granted, created_at, updated_at)
VALUES (:user_id, :purpose, 1, :now, :now)
ON CONFLICT(user_id, purpose) DO UPDATE
SET granted = 1, updated_at = :now

// 撤回——相同模式
INSERT INTO consents (user_id, purpose, granted, created_at, updated_at)
VALUES (:user_id, :purpose, 0, :now, :now)
ON CONFLICT(user_id, purpose) DO UPDATE
SET granted = 0, updated_at = :now
```

`UNIQUE(user_id, purpose)` 上的 UPSERT 是原子的——防止同时 grant+withdraw 创建重复行的竞态条件。

---

## 核心模式：不可变历史

```php
// 始终追加到历史记录——即使重复授予也被记录
INSERT INTO consent_history (user_id, purpose, granted, created_at)
VALUES (:user_id, :purpose, 1, :now)
```

历史记录**永不更新**——它是每次同意变更的审计日志。这让监管机构可以验证何时授予同意以及何时撤回。

---

## 核心模式：purpose 校验

```php
private function resolvePurpose(mixed $raw): ?string
{
    // VULN-G：类型混淆——必须是字符串
    if (!is_string($raw)) {
        return null;
    }

    $len = strlen($raw);

    if ($len === 0 || $len > self::MAX_PURPOSE_LEN) {
        return null;
    }

    // VULN-I：ctype_alnum 是 O(n)——无正则表达式，无 ReDoS
    // VULN-D：仅字母数字——无 HTML、无 SQL 特殊字符
    if (!ctype_alnum($raw)) {
        return null;
    }

    return $raw;
}
```

`ctype_alnum()` 只接受 `[a-zA-Z0-9]`——在单次 O(n) 扫描中拒绝空格、连字符、SQL 元字符和 HTML 标签。

---

## 核心模式：用户枚举防护

```php
// VULN-F：对未知用户返回空数组——不返回 404
public function listForUser(int $userId): array
{
    $stmt = $this->pdo->prepare(
        'SELECT ... FROM consents WHERE user_id = :user_id ORDER BY purpose ASC',
    );
    $stmt->execute(['user_id' => $userId]);

    return array_map(fn(array $r) => $this->hydrateConsent($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
}
```

对未知用户返回 404 泄露了"这个 user_id 不存在"的信息。始终返回带空数据的 200。

---

## 核心模式：IDOR 防护

```php
// VULN-B：所有读写都限定到已认证用户
// 即使攻击者发送 X-User-Id: 999，他们也只看到用户 999 的数据
WHERE user_id = :user_id AND purpose = :purpose
```

没有跨用户查询会触及其他用户的记录。

---

## 核心模式：服务器控制的 granted 字段

```php
// VULN-C/E：granted 由端点控制——永不从请求体读取
// POST /consents → 始终授予（granted = 1）
// DELETE /consents/{purpose} → 始终撤回（granted = 0）
// POST 上的请求体 { "granted": false } 被静默忽略
```

端点本身决定 `granted` 值。请求体字段永远无法覆盖它。

---

## 响应设计

| 场景 | 状态 | 请求体 |
|---|---|---|
| 授予成功 | 201 | `{consent: {id, purpose, granted: true, updated_at}}` |
| 撤回成功 | 200 | `{consent: {id, purpose, granted: false, updated_at}}` |
| 列出同意 | 200 | `{data: [...], total: N}` |
| 历史记录 | 200 | `{data: [{id, purpose, granted, created_at}, ...], total: N}` |
| 未知用户 | 200 | `{data: [], total: 0}`——而非 404 |

`user_id` **永不**包含在任何响应中——它从 `X-User-Id` 隐式得出。

---

## VULN-A~L 全部通过

| VULN | 攻击 | 防御 |
|---|---|---|
| A | X-User-Id 中的 SQL 注入 | `ctype_digit()` + strlen > 18 守护 |
| B | IDOR——操纵其他用户的同意 | 所有查询带 `WHERE user_id = :user_id` |
| C | 批量赋值（篡改 granted 字段） | granted 由端点决定——不从请求体读取 |
| D | purpose 中的 XSS | `ctype_alnum()`——仅字母数字 |
| E | 直接修改同意状态 | grant/withdraw 是独立端点 |
| F | 用户枚举 | 未知 user_id 返回空数组 200 |
| G | 类型混淆（purpose 为 int/array/null） | `is_string()` + `ctype_alnum()` |
| H | 同意重放 | 历史记录仅追加，重新授予是新条目 |
| I | purpose 中的 ReDoS | `ctype_alnum()` O(n) |
| J | X-User-Id 中的整数溢出 | strlen > 18 守护 |
| K | 同时 grant+withdraw 竞态条件 | `UNIQUE(user_id, purpose)` UPSERT 原子性 |
| L | 请求头中的 CRLF 注入 | PSR-7 在 HTTP 层拒绝 |

---

## 测试结果（FT189）

```
51 个测试 / 142 个断言——全部通过
PHPStan level 8——无错误
PHP CS Fixer——干净
VULN-A~L 全部通过
```

来源：[`../NENE2-FT/consentlog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/consentlog)
