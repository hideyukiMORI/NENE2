# 操作指南：习惯追踪 API

> **FT 参考**：FT24（`NENE2-FT/habitlog`）——带连续打卡计算的习惯追踪 API
> **ATK**：FT224——破解者思维攻击测试（ATK-01 至 ATK-12）

演示带连续打卡计算、重复完成防护（409 Conflict）和频率白名单的习惯追踪 REST API。ATK 章节记录了破解者思维发现的每个攻击面，并记录是否已防御或存在暴露。

---

## 路由

| 方法 | 路径 | 描述 |
|------|------|------|
| `GET` | `/habits` | 列出所有习惯（`?frequency=`） |
| `POST` | `/habits` | 创建习惯 |
| `GET` | `/habits/{id}` | 获取单条习惯 |
| `DELETE` | `/habits/{id}` | 删除习惯（级联） |
| `POST` | `/habits/{id}/completions` | 记录完成（日期级别幂等） |
| `GET` | `/habits/{id}/completions` | 列出习惯的完成记录 |
| `GET` | `/habits/{id}/streak` | 当前连续打卡（`?today=YYYY-MM-DD`） |

---

## 创建习惯

```php
// POST /habits
$body = [
    'name'        => 'Morning Run',        // 必填，非空字符串
    'description' => 'Run 5 km',           // 可选
    'frequency'   => 'daily',              // 'daily' | 'weekly' | 'monthly'
];
```

`frequency` 根据明确的白名单进行校验。其他任何值返回 422。

```php
private function createHabit(ServerRequestInterface $req): mixed
{
    $body      = JsonRequestBodyParser::parse($req);
    $name      = isset($body['name']) ? trim((string) $body['name']) : '';
    $frequency = isset($body['frequency']) ? (string) $body['frequency'] : 'daily';

    $errors = [];
    if ($name === '') {
        $errors[] = new ValidationError('name', 'Name must not be empty.', 'required');
    }

    $validFrequencies = ['daily', 'weekly', 'monthly'];
    if (!in_array($frequency, $validFrequencies, true)) {
        $errors[] = new ValidationError('frequency', 'Frequency must be daily, weekly, or monthly.', 'invalid_value');
    }

    if ($errors !== []) {
        throw new ValidationException($errors);
    }
    // ...
}
```

---

## 带重复防护的完成记录

完成记录通过 `UNIQUE` 约束按 `(habit_id, completed_on)` 作为键。同一日期的第二次 POST 返回 **409 Conflict** 而不修改数据库行。

```sql
-- schema.sql
UNIQUE(habit_id, completed_on)
```

```php
public function complete(int $habitId, string $completedOn, string $note): Completion
{
    try {
        $this->executor->execute(
            'INSERT INTO completions (habit_id, completed_on, note) VALUES (?, ?, ?)',
            [$habitId, $completedOn, $note],
        );
    } catch (DatabaseConnectionException $e) {
        $previous = $e->getPrevious();
        if ($previous !== null && str_contains($previous->getMessage(), 'UNIQUE constraint failed')) {
            throw new AlreadyCompletedException($habitId, $completedOn);
        }
        throw $e;
    }

    return new Completion($this->executor->lastInsertId(), $habitId, $completedOn, $note);
}
```

控制器将 `AlreadyCompletedException` 映射为 409，先于 NENE2 的全局错误处理程序，因此响应正确使用 Problem Details。

---

## 连续打卡计算

从 `$today` 向后计算连续每日完成次数。

```php
public function currentStreak(int $habitId, string $today): int
{
    $rows = $this->executor->fetchAll(
        'SELECT completed_on FROM completions WHERE habit_id = ? ORDER BY completed_on DESC',
        [$habitId],
    );

    $streak   = 0;
    $expected = new \DateTimeImmutable($today);

    foreach ($rows as $row) {
        $date = new \DateTimeImmutable((string) $row['completed_on']);
        if ($date->format('Y-m-d') !== $expected->format('Y-m-d')) {
            break;
        }
        $streak++;
        $expected = $expected->modify('-1 day');
    }

    return $streak;
}
```

`?today=YYYY-MM-DD` 覆盖参考日期，使测试无需模拟 `date()` 即可确定性运行。

---

## 日期格式校验

`completed_on` 字段通过正则表达式校验，而非语义解析：

```php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $completedOn)) {
    throw new ValidationException([
        new ValidationError('completed_on', 'Date must be in YYYY-MM-DD format.', 'invalid_format'),
    ]);
}
```

这正确拒绝 `"not-a-date"` 但接受 `"2026-02-30"`。对于严格的语义校验，添加 `DateTimeImmutable` 往返检查：

```php
// 更严格的校验（生产环境推荐）：
$dt = DateTimeImmutable::createFromFormat('Y-m-d', $completedOn);
if ($dt === false || $dt->format('Y-m-d') !== $completedOn) {
    throw new ValidationException([...]);
}
```

---

## 路径参数安全

路径 `{id}` 使用零值回退转换为 `int`：

```php
$id = (int) ($req->getAttribute(Router::PARAMETERS_ATTRIBUTE, [])['id'] ?? 0);
```

非数字字符串变为 `0`。不存在 `id = 0` 的习惯，因此处理程序经过 `null` 检查后返回 404。这里无需 `ctype_digit()`，但注意 `(int) "9abc"` 得到 `9`——如果路由必须拒绝非数字路径，应改用 `ctype_digit()`。

---

## 数据库结构：级联删除

```sql
CREATE TABLE completions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    habit_id     INTEGER NOT NULL REFERENCES habits(id) ON DELETE CASCADE,
    completed_on TEXT    NOT NULL,
    note         TEXT    NOT NULL DEFAULT '',
    UNIQUE(habit_id, completed_on)
);
```

`ON DELETE CASCADE` 确保父习惯被删除时完成记录也随之删除。
使用 SQLite 时需用 `PRAGMA foreign_keys = ON` 启用外键强制。

---

## ATK——破解者攻击测试（FT224）

以下每项发现都记录了一个攻击向量、观察到的结果以及判定：**BLOCKED**（安全）、**EXPOSED**（真实漏洞）或 **ACCEPTED BY DESIGN**（有文档记录的有意权衡）。

### ATK-01 — 所有端点均无认证

**攻击**：无需任何凭证即可创建、读取或删除习惯。

```http
POST /habits
Content-Type: application/json

{"name": "Attacker habit", "frequency": "daily"}
```

**观察**：`201 Created`——无令牌、会话或密钥即成功。

**判定**：**EXPOSED**（FT24 演示中按设计如此）。
生产习惯追踪器必须在变更操作后加认证。
NENE2 的 `MachineApiKeyMiddleware` 或 JWT Bearer 中间件可解决此问题。

---

### ATK-02 — 无所有权：读取 / 删除任意习惯

**攻击**：不知道是谁的习惯，枚举并删除所有习惯。

```http
GET /habits         → 列出系统中所有习惯
DELETE /habits/1    → 无论谁创建都删除习惯 #1
```

**观察**：列出返回 `200 OK`，删除返回 `200 OK`。

**判定**：**EXPOSED**（FT24 演示中按设计如此）。
添加 `user_id` 列、写路径所有权检查，以及未授权访问时返回 404（而非 403）（IDOR 保护——参见 FT222 `notificationlog`）。

---

### ATK-03 — 通过参数化查询防止 SQL 注入

**攻击**：通过 `name`、`frequency` 或 `completed_on` 注入 SQL。

```json
{"name": "x' OR '1'='1", "frequency": "daily"}
{"completed_on": "2026-01-01' OR '1'='1"}
```

**观察**：名称原样存储。完成记录被日期格式正则在到达 DB 层之前拒绝。

**判定**：**BLOCKED** — 所有查询使用 PDO 参数化语句。频率白名单在应用层阻止该字段的注入。

---

### ATK-04 — 接受了语义无效的日期

**攻击**：提交结构正确但日历无效的日期。

```json
{"completed_on": "2026-02-30"}
{"completed_on": "2026-13-01"}
{"completed_on": "0000-00-00"}
```

**观察**：`201 Created`——正则 `^\d{4}-\d{2}-\d{2}$` 通过；PDO 原样存储字符串；`DateTimeImmutable` 静默规范化（例如 `2026-02-30` 变为 `2026-03-02`），破坏连续打卡计数。

**判定**：**EXPOSED** — 添加往返检查：
```php
$dt = DateTimeImmutable::createFromFormat('Y-m-d', $completedOn);
if ($dt === false || $dt->format('Y-m-d') !== $completedOn) {
    throw new ValidationException([...]);
}
```

---

### ATK-05 — 非数字路径 ID

**攻击**：发送非数字或负值作为 `{id}`。

```http
GET  /habits/abc
GET  /habits/-1
GET  /habits/0
GET  /habits/1.5
```

**观察**：全部返回 `404 Not Found`。`(int) "abc"` = `0`，`(int) "-1"` = `-1`，`(int) "1.5"` = `1`。这些 ID 处不存在习惯，所以 `findById()` 返回 `null`。

**判定**：**BLOCKED**（实际上）（不存在 ID ≤ 0 的习惯）。但 `(int) "9abc"` = `9`——如果 ID 为 9 的习惯存在，它会被返回。当差异重要时，使用 `ctype_digit()` 进行严格路径 ID 校验。

---

### ATK-06 — 同日期重复完成

**攻击**：两次 POST 同一 `(habit_id, completed_on)` 以虚增连续打卡。

```http
POST /habits/1/completions {"completed_on": "2026-05-20"}
POST /habits/1/completions {"completed_on": "2026-05-20"}
```

**观察**：第二次请求返回 `409 Conflict`——DB 层的 UNIQUE 约束触发，捕获 `AlreadyCompletedException`，返回 Problem Details 响应。

**判定**：**BLOCKED** — DB 约束是权威防线；应用层将其映射为格式良好的 409。

---

### ATK-07 — name/note 中的 XSS 载荷

**攻击**：在 `name` 或 `note` 中存储 script 标签。

```json
{"name": "<script>alert(document.cookie)</script>", "frequency": "daily"}
```

**观察**：`201 Created`。载荷原样存储并在 JSON 响应中原样返回。

**判定**：**ACCEPTED BY DESIGN** — 这是一个 JSON API；转义是渲染客户端的责任。服务器不从这些字段生成 HTML。在 API 规范中清楚记录这一合约。

---

### ATK-08 — 极长习惯名称

**攻击**：发送包含数万字符的名称以耗尽存储或导致缓慢序列化。

```php
'name' => str_repeat('A', 50_000)
```

**观察**：`201 Created`——应用层未执行长度限制。SQLite TEXT 无界；行被插入。

**判定**：**EXPOSED** — 在控制器的校验块中添加最大长度检查（例如 200 字符）并返回 422：
```php
if (mb_strlen($name) > 200) {
    $errors[] = new ValidationError('name', 'Name must not exceed 200 characters.', 'max_length');
}
```

---

### ATK-09 — 纯空白的习惯名称

**攻击**：发送全是空白的名称。

```json
{"name": "   "}
```

**观察**：`422 Unprocessable Entity`——`trim()` 将值折叠为 `''`，触发 `required` 校验错误。

**判定**：**BLOCKED** — 空字符串检查前的 `trim()` 覆盖了这种情况。

---

### ATK-10 — 通过 `?today=` 查询参数操纵连续打卡

**攻击**：覆盖参考日期以声明历史连续打卡。

```http
GET /habits/1/streak?today=2099-12-31
GET /habits/1/streak?today=not-a-date
```

**观察**：`today=2099-12-31` → streak = 0（未来没有完成记录）。`today=not-a-date` → PHP `DateTimeImmutable` 在格式不正确的值上抛出内部异常（在默认错误处理程序中变为 500）。

**判定**：**PARTIALLY EXPOSED** — 在传给 `currentStreak()` 前用正则或往返检查校验 `today`：
```php
$today = QueryStringParser::string($req, 'today') ?? date('Y-m-d');
$dt    = DateTimeImmutable::createFromFormat('Y-m-d', $today);
if ($dt === false || $dt->format('Y-m-d') !== $today) {
    $today = date('Y-m-d'); // 回退到服务器日期
}
```

---

### ATK-11 — 为不存在的习惯记录完成

**攻击**：为不存在的习惯 ID 发送 POST 完成记录。

```http
POST /habits/99999/completions
{"completed_on": "2026-05-20"}
```

**观察**：`404 Not Found`——`findById(99999)` 返回 `null`，控制器在尝试 INSERT 之前返回未找到响应。

**判定**：**BLOCKED** — 存在检查发生在 DB 写入之前。

---

### ATK-12 — 查询参数中的路径遍历 / 注入

**攻击**：通过 `frequency` 过滤器注入路径遍历或 shell 注入字符串。

```http
GET /habits?frequency=../../../etc/passwd
GET /habits?frequency='; DROP TABLE habits; --
```

**观察**：两者都返回 `200 OK` 并带有空 `habits` 数组。`frequency` 值仅在 `array_filter` 中与存储值进行严格 `===` 比较。没有从中构建 DB 查询。

**判定**：**BLOCKED** — 按查询参数过滤在 PHP 内存中应用，而非作为原始 SQL `WHERE` 子句。不触发文件 I/O 或 shell 执行。

---

## ATK 汇总

| # | 向量 | 判定 |
|---|------|------|
| ATK-01 | 无认证 | EXPOSED（按设计） |
| ATK-02 | 无所有权 / IDOR | EXPOSED（按设计） |
| ATK-03 | SQL 注入 | BLOCKED |
| ATK-04 | 语义无效日期 | EXPOSED |
| ATK-05 | 非数字路径 ID | BLOCKED |
| ATK-06 | 重复完成 | BLOCKED |
| ATK-07 | XSS 载荷存储 | ACCEPTED BY DESIGN |
| ATK-08 | 无界名称长度 | EXPOSED |
| ATK-09 | 纯空白名称 | BLOCKED |
| ATK-10 | `?today=` 操纵 | PARTIALLY EXPOSED |
| ATK-11 | 为不存在的习惯记录完成 | BLOCKED |
| ATK-12 | 查询字符串中的路径遍历 / 注入 | BLOCKED |

**生产前需修复的真实漏洞**：
1. **ATK-01/02** — 添加认证和所有权
2. **ATK-04** — 添加语义日期校验（通过 `DateTimeImmutable` 往返）
3. **ATK-08** — 对 `name`/`note` 添加 `mb_strlen()` 最大长度检查
4. **ATK-10** — 在传给业务逻辑前校验 `?today=`

---

## 相关操作指南

- [`notification-inbox.md`](notification-inbox.md) — IDOR 保护模式（未授权读取时 404）
- [`expense-tracker.md`](expense-tracker.md) — 严格 `is_int()` 类型检查和 ISO 日期往返校验
- [`session-management.md`](session-management.md) — 在此模式之上添加认证层
