# 操作指南：投票/调查 API

本指南展示如何使用 NENE2 构建带重复投票防护的投票和调查系统。模式由 **polllog** 字段试验（FT217）验证。

## 功能

- 创建带 2–20 个选项的投票（仅管理员）
- 公开和私有投票（私有：仅管理员访问）
- 每用户每投票只能投一票（由 UNIQUE 约束强制执行）
- 每选项投票数的实时结果聚合
- 所有选项的总投票数

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS polls (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    question   TEXT    NOT NULL,
    is_public  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS poll_options (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id    INTEGER NOT NULL,
    label      TEXT    NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id    INTEGER NOT NULL,
    option_id  INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (poll_id, user_id),  -- 每用户每投票只能投一票
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_votes_poll ON votes (poll_id, option_id);
```

## 端点

| 方法 | 路径 | 认证 | 描述 |
|--------|------|------|-------------|
| `POST` | `/polls` | 管理员 | 创建带选项的投票 |
| `GET` | `/polls/{id}` | 公开 | 获取投票（私有 → 非管理员返回 404） |
| `POST` | `/polls/{id}/vote` | 用户 | 投票 |
| `GET` | `/polls/{id}/results` | 公开 | 获取每选项投票数结果 |

## 选项校验

```php
private const int MIN_OPTIONS   = 2;
private const int MAX_OPTIONS   = 20;
private const int MAX_LABEL_LEN = 100;

foreach ($rawOptions as $idx => $label) {
    if (!is_string($label) || trim($label) === '') {
        return $this->problem(422, 'validation-failed', "options[{$idx}] must not be empty.");
    }
    if (strlen($label) > self::MAX_LABEL_LEN) {
        return $this->problem(422, 'validation-failed', "options[{$idx}] too long (max 100).");
    }
}
```

## 重复投票防护

```php
/** @return 'ok'|'already_voted'|'invalid_option' */
public function vote(int $pollId, int $userId, int $optionId): string
{
    // 验证选项属于该投票（防止跨投票选项注入）
    $stmt = $this->pdo->prepare(
        'SELECT id FROM poll_options WHERE id = :oid AND poll_id = :pid'
    );
    $stmt->execute([':oid' => $optionId, ':pid' => $pollId]);
    if ($stmt->fetch() === false) {
        return 'invalid_option'; // → 422
    }

    // 检查现有投票
    $stmt2 = $this->pdo->prepare(
        'SELECT id FROM votes WHERE poll_id = :pid AND user_id = :uid'
    );
    if ($stmt2->fetch() !== false) {
        return 'already_voted'; // → 409
    }

    // INSERT——UNIQUE(poll_id, user_id) 约束是最后安全网
    $this->pdo->prepare('INSERT INTO votes ...')->execute([...]);
    return 'ok';
}
```

## 结果聚合

使用 `LEFT JOIN` 确保零票的选项也出现在结果中：

```sql
SELECT o.id, o.label, o.sort_order,
       COUNT(v.id) AS votes
FROM poll_options o
LEFT JOIN votes v ON v.option_id = o.id AND v.poll_id = o.poll_id
WHERE o.poll_id = :pid
GROUP BY o.id, o.label, o.sort_order
ORDER BY o.sort_order ASC, o.id ASC
```

```php
$results    = $this->repo->results($id);
$totalVotes = array_sum(array_column($results, 'votes'));

return $this->json([
    'poll_id'     => $id,
    'total_votes' => $totalVotes,
    'results'     => $results,
]);
```

## 私有投票访问控制

私有投票对非管理员用户返回 404（隐藏存在性）：

```php
// GET /polls/{id}
if (!(bool) $poll['is_public'] && !$this->isAdmin($req)) {
    return $this->problem(404, 'not-found', 'Poll not found.');
}
```

## 安全模式

- **管理员故障关闭**：在 `hash_equals()` 之前执行 `if ($this->adminKey === '') return false;`
- **`is_int()`**：`option_id` 的严格类型检查——拒绝浮点数/字符串
- **`ctype_digit()`**：路径 ID 的 ReDoS 安全整数校验
- **跨投票选项注入**：`WHERE id = :oid AND poll_id = :pid` 防止使用其他投票的选项
- **`is_bool()`**：`is_public` 标志的严格检查——拒绝 `1`/`0`/`"true"` 等
