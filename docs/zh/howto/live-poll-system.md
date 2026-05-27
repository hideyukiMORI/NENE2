# 操作指南：实时投票系统

## 概述

本指南介绍如何使用 NENE2 构建实时投票系统 API，包括管理员门控的投票创建、每用户投票去重、投票生命周期管理和结果汇总。

**参考实现**：`../NENE2-FT/polllog/`

---

## 数据库结构设计

```sql
CREATE TABLE IF NOT EXISTS polls (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    question   TEXT    NOT NULL,
    closed     INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS poll_options (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id INTEGER NOT NULL,
    label   TEXT    NOT NULL,
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS poll_votes (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id   INTEGER NOT NULL,
    option_id INTEGER NOT NULL,
    user_id   INTEGER NOT NULL,
    voted_at  TEXT    NOT NULL,
    UNIQUE (poll_id, user_id),
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE
);
```

关键约束：
- `UNIQUE (poll_id, user_id)` — 防止用户在同一投票中投票超过一次。
- `ON DELETE CASCADE` — 删除投票时移除对应的选项和票数。

---

## 路由表

| 方法 | 路径 | 认证 | 描述 |
|------|------|------|------|
| `POST` | `/polls` | 管理员 | 创建带选项的投票 |
| `GET` | `/polls` | 无 | 列出所有投票 |
| `GET` | `/polls/{id}` | 无 | 获取投票及票数 |
| `POST` | `/polls/{id}/vote` | 用户 | 投票 |
| `POST` | `/polls/{id}/close` | 管理员 | 关闭投票 |

---

## 管理员认证模式

在 `X-Admin-Key` 请求头中传入共享密钥。使用故障关闭逻辑：

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;          // 故障关闭：未配置密钥 → 永远不是管理员
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

非管理员时返回 `403 Forbidden`：
```php
if (!$this->isAdmin($req)) {
    return $this->problem(403, 'forbidden', 'Admin key required.');
}
```

---

## 创建带选项的投票

校验至少 2 个选项；在事务中插入：

```php
public function create(string $question, array $options): array
{
    $now  = $this->now();
    $stmt = $this->pdo->prepare('INSERT INTO polls (question, closed, created_at) VALUES (?, 0, ?)');
    $stmt->execute([$question, $now]);
    $pollId = (int) $this->pdo->lastInsertId();

    $ins = $this->pdo->prepare('INSERT INTO poll_options (poll_id, label) VALUES (?, ?)');
    foreach ($options as $label) {
        $ins->execute([$pollId, $label]);
    }

    return $this->findById($pollId);
}
```

---

## 带去重的投票

捕获 UNIQUE 约束违反以检测重复投票：

```php
public function vote(int $pollId, int $optionId, int $userId): string
{
    $poll = $this->findById($pollId);
    if ($poll === null) return 'not_found';
    if ($poll['closed']) return 'poll_closed';

    // 验证选项属于此投票
    $stmt = $this->pdo->prepare('SELECT id FROM poll_options WHERE id = ? AND poll_id = ?');
    $stmt->execute([$optionId, $pollId]);
    if ($stmt->fetch() === false) return 'invalid_option';

    try {
        $this->pdo->prepare(
            'INSERT INTO poll_votes (poll_id, option_id, user_id, voted_at) VALUES (?, ?, ?, ?)'
        )->execute([$pollId, $optionId, $userId, $this->now()]);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) return 'already_voted';
        throw $e;
    }

    return 'ok';
}
```

---

## 汇总票数

使用 `LEFT JOIN` 包含零票数的选项：

```sql
SELECT po.id, po.label, COUNT(pv.id) AS votes
FROM poll_options po
LEFT JOIN poll_votes pv ON pv.option_id = po.id
WHERE po.poll_id = :poll_id
GROUP BY po.id, po.label
ORDER BY po.id ASC
```

---

## HTTP 状态码

| 情况 | 状态码 |
|------|--------|
| 投票已创建 | 201 |
| 投票已投出 | 201 |
| 找到/关闭的投票 | 200 |
| 投票不存在 | 404 |
| 无效的选项 ID | 422 |
| 缺少问题或少于 2 个选项 | 422 |
| option_id 非整数 | 422 |
| 已投过票 | 409 |
| 对已关闭的投票投票 | 409 |
| 无管理员密钥 | 403 |
| 无 X-User-Id 请求头 | 400 |

---

## 校验检查清单

- `question`：非空字符串
- `options`：≥ 2 个非空字符串的数组
- `option_id`：必须是 `is_int()`（拒绝 `'1'` 等字符串）
- `X-User-Id`：`ctype_digit()` + 正整数
- 投票或关闭前投票必须存在
- 选项必须属于目标投票（跨投票注入防护）

---

## 安全说明

- **管理员密钥故障关闭**：空密钥意味着没有人是管理员。
- **使用 `hash_equals()`** 防止管理员密钥比较的时序攻击。
- **UNIQUE 约束** 是权威性的重复投票防护——仅靠应用层检查在并发负载下不够充分。
- **选项所有权检查** 防止使用来自不同投票的选项投票。
