# 操作指南：班次管理 API

> **FT 参考**：FT43（`NENE2-FT/shiftlog`）— 员工排班 API
> **VULN**：FT225 — 安全/漏洞评估（V-01 到 V-12）

演示带重叠检测的员工排班 API，包含事务作用域检查、ISO 8601 日期比较，以及针对领域错误的自定义异常处理器。
VULN 部分系统性地评估每个攻击面并记录各项结果。

---

## 路由

| 方法 | 路径 | 说明 |
|------|------|------|
| `GET` | `/employees` | 列出员工（分页） |
| `POST` | `/employees` | 创建员工 |
| `GET` | `/employees/{id}` | 获取单个员工 |
| `GET` | `/employees/{id}/shifts` | 列出员工的班次（分页） |
| `POST` | `/shifts` | 安排班次（含重叠检测） |
| `GET` | `/shifts/{id}` | 获取单个班次 |
| `DELETE` | `/shifts/{id}` | 删除班次 |
| `GET` | `/schedule` | 日期窗口内的班次（`?from=&to=`） |
| `GET` | `/summary/weekly` | 每员工每周工时 |
| `GET` | `/summary/overtime` | 超过工时阈值的员工 |

---

## 创建员工

```php
// POST /employees
$body = [
    'name'        => 'Alice',    // 必填，非空字符串
    'role'        => 'Barista',  // 必填，非空字符串
    'hourly_rate' => 18.50,      // 必填，数字 > 0
];
```

应用 `is_int()` / `is_string()` 严格 JSON 类型检查。`trim()` 后的空字符串被拒绝。

```php
if (!isset($body['hourly_rate'])
    || !is_numeric($body['hourly_rate'])
    || (float) $body['hourly_rate'] <= 0) {
    $errors[] = new ValidationError('hourly_rate', 'hourly_rate must be a positive number.', 'required');
}
```

> **注意**：数据库层还有 `CHECK(hourly_rate > 0)` 作为深度防御的保底措施。先在应用层校验，以便返回正确的 422。

---

## 带重叠检测的班次安排

重叠检测在数据库事务内运行，以防止竞争条件：

```php
return $this->txManager->transactional(
    function (DatabaseQueryExecutorInterface $tx) use ($employeeId, $startsAt, $endsAt, $location, $now): Shift {
        $txRepo   = new self($tx, $this->txManager);
        $employee = $txRepo->findEmployeeById($employeeId);

        // 重叠：任何与 [$startsAt, $endsAt) 相交的现有班次
        $overlap = $tx->fetchOne(
            "SELECT id FROM shifts
             WHERE employee_id = ?
               AND starts_at < ?
               AND ends_at   > ?",
            [$employeeId, $endsAt, $startsAt],
        );

        if ($overlap !== null) {
            throw new ShiftOverlapException($employee->name, $startsAt, $endsAt);
        }

        $id = $tx->insert(
            'INSERT INTO shifts (employee_id, starts_at, ends_at, location, created_at) VALUES (?, ?, ?, ?, ?)',
            [$employeeId, $startsAt, $endsAt, $location, $now],
        );
        // ...
    },
);
```

重叠条件 `starts_at < $endsAt AND ends_at > $startsAt` 正确处理了所有四种重叠配置（左侧部分重叠、右侧部分重叠、包含、被包含）。

**为什么需要事务？** 没有事务时，两个并发请求可能同时通过重叠检查并创建冲突班次。事务将读取-检查-写入序列串行化。

---

## ends_at > starts_at 校验

应用层在 DB 之前校验时间顺序：

```php
if ($endsAt <= $startsAt) {
    throw new ValidationException([
        new ValidationError('ends_at', 'ends_at must be after starts_at.', 'invalid_range'),
    ]);
}
```

数据库层添加 `CHECK(ends_at > starts_at)` 作为保底。两层共同确保无效时间范围永远无法进入数据存储。

---

## ISO 8601 日期字符串比较

班次时间以 ISO 8601 字符串存储（`2026-05-27T09:00:00+09:00`），在 SQL 中按字典序比较。**只有所有时间使用相同的时区偏移或 UTC 时**，这才能正确工作。混合偏移比较可能产生错误结果：

```
"2026-05-27T09:00:00+09:00" < "2026-05-27T01:00:00Z"  → 错误（同一时刻）
```

**建议**：存储前将所有日期时间规范化为 UTC：

```php
$utc      = new \DateTimeZone('UTC');
$startsAt = (new \DateTimeImmutable($raw))->setTimezone($utc)->format(\DateTimeInterface::ATOM);
```

---

## 自定义异常 → HTTP 响应映射

领域异常通过处理器映射为结构化 Problem Details 响应：

```php
final readonly class ShiftOverlapExceptionHandler implements DomainExceptionHandlerInterface
{
    public function supports(\Throwable $exception): bool
    {
        return $exception instanceof ShiftOverlapException;
    }

    public function handle(\Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        return $this->factory->create(
            $request,
            'shift-overlap',
            'Shift overlaps with an existing shift.',
            409,
            $exception->getMessage(),
        );
    }
}
```

`ShiftNotFoundException` → 404、`EmployeeNotFoundException` → 404、`ShiftOverlapException` → 409 各有独立处理器。在 `RuntimeApplicationFactory` 中注册它们，使控制器无需 `try/catch` 样板代码。

---

## 聚合查询：周报和加班统计

```php
// GET /summary/weekly?from=2026-05-19&to=2026-05-25
// GET /summary/overtime?from=2026-05-19&to=2026-05-25&threshold=40
```

加班阈值默认为 40 小时：

```php
$threshold = (float) (QueryStringParser::int($request, 'threshold') ?? 40);
if ($threshold <= 0) {
    throw new ValidationException([...]);
}
```

注意：先使用 `QueryStringParser::int()`（拒绝非数字字符串），再转换为 `float`。这防止 `NaN` / `Infinity` 进入业务层。

---

## 数据库结构：级联删除和 DB 层约束

```sql
CREATE TABLE employees (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    role        TEXT    NOT NULL,
    hourly_rate REAL    NOT NULL CHECK(hourly_rate > 0),
    created_at  TEXT    NOT NULL
);

CREATE TABLE shifts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
    starts_at   TEXT    NOT NULL,
    ends_at     TEXT    NOT NULL,
    location    TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    CHECK(ends_at > starts_at)
);
```

`ON DELETE CASCADE` 在删除员工时同时删除其班次。
DB 层 `CHECK` 约束是深度防御的保底，而非主要校验层——应用层校验必须在任何 DB INSERT 之前返回 422。

---

## VULN — 安全评估（FT225）

每项结果记录攻击向量、观察结果和结论：
**BLOCKED**（安全）、**EXPOSED**（真实漏洞）、**PARTIALLY EXPOSED**（部分暴露）或 **ACCEPTED BY DESIGN**（设计接受）。

### V-01 — 所有端点无认证

**攻击**：无凭据创建员工、安排班次或删除班次。

```http
POST /employees
{"name": "Attacker", "role": "Ghost", "hourly_rate": 0.01}

DELETE /shifts/1
```

**观察结果**：两者均成功。不需要令牌、session 或 API 密钥。

**结论**：**EXPOSED**（FT43 演示的设计选择）。
生产排班系统必须在变更操作后加上认证。
使用 `MachineApiKeyMiddleware`（env: `NENE2_MACHINE_API_KEY`）或 JWT Bearer。

---

### V-02 — 无授权：任何人都可以删除任意班次

**攻击**：无所有权检查即删除属于其他员工的班次。

```http
DELETE /shifts/1   # 任何已认证或未认证的调用者均成功
```

**观察结果**：无论调用者身份如何，返回 `204 No Content`。

**结论**：**EXPOSED**（FT43 演示的设计选择）。
删除前添加管理员角色检查，或将班次与请求用户绑定。

---

### V-03 — 通过参数化查询防止 SQL 注入

**攻击**：通过 `name`、`role`、`starts_at` 或 `location` 注入 SQL。

```json
{"name": "x'; DROP TABLE employees; --", "role": "Admin", "hourly_rate": 1}
{"starts_at": "2026-01-01' OR '1'='1", "ends_at": "2026-01-02", "employee_id": 1}
```

**观察结果**：员工以注入字符串作为名称被创建。班次的 `starts_at` 使用参数化查询，因此不会发生 SQL 注入。

**结论**：**BLOCKED** — 所有查询使用 PDO 参数化语句。存储的字符串在 DB 中无害；唯一的风险是如果它之后被渲染为 HTML。

---

### V-04 — 班次重叠检测竞争条件

**攻击**：对同一员工发送两个具有重叠时间窗口的并发 `POST /shifts` 请求。

**观察结果**：重叠检查在 `transactional()` 内运行。SQLite 使用 WAL 模式锁定串行化写入；MySQL/PostgreSQL 在事务管理器正确配置时使用 `REPEATABLE READ` 或 `SERIALIZABLE` 隔离级别。两个并发插入不可能同时通过重叠检查。

**结论**：**BLOCKED** — 事务重叠检查防止并发下的双重预订。验证隔离级别与 DB 引擎匹配；SQLite 的 WAL 默认值足够单节点部署使用。

---

### V-05 — 接受 ends_at ≤ starts_at

**攻击**：提交结束时间早于或等于开始时间的班次。

```json
{"employee_id": 1, "starts_at": "2026-05-27T10:00:00Z", "ends_at": "2026-05-27T09:00:00Z"}
{"employee_id": 1, "starts_at": "2026-05-27T10:00:00Z", "ends_at": "2026-05-27T10:00:00Z"}
```

**观察结果**：`422 Unprocessable Entity` — 应用层在插入前比较字符串（`$endsAt <= $startsAt`）。DB 的 `CHECK(ends_at > starts_at)` 是保底。

**结论**：**BLOCKED** — 双层校验（应用层 + DB 约束）。

---

### V-06 — hourly_rate 校验缺口

**攻击**：为 `hourly_rate` 提交负数、零或字符串值。

```json
{"name": "X", "role": "Y", "hourly_rate": -10}
{"name": "X", "role": "Y", "hourly_rate": 0}
{"name": "X", "role": "Y", "hourly_rate": "free"}
```

**观察结果**：
- 负数/零：应用层在控制器层**未**校验 `hourly_rate > 0`。负值绕过应用检查后碰到 DB 的 `CHECK(hourly_rate > 0)`，触发 DB 异常。没有显式处理器的情况下，这会变成 500。
- 字符串 `"free"`：`is_numeric()` 返回 false，因此被 422 拒绝。

**结论**：**PARTIALLY EXPOSED** — 在 DB 插入前添加应用层校验：
```php
if (!isset($body['hourly_rate'])
    || !is_numeric($body['hourly_rate'])
    || (float) $body['hourly_rate'] <= 0) {
    $errors[] = new ValidationError('hourly_rate', 'hourly_rate must be a positive number.', 'out_of_range');
}
```

---

### V-07 — 语义上无效的 ISO 8601 日期时间

**攻击**：提交结构上合理但日历上无效的日期时间的班次。

```json
{"starts_at": "2026-02-30T00:00:00Z", "ends_at": "2026-02-30T08:00:00Z", "employee_id": 1}
```

**观察结果**：被接受并存储。应用层只检查 `trim() === ''` 但不解析日期。`DateTimeImmutable` 静默地将 `2026-02-30` 规范化为 `2026-03-02`，导致存储值损坏。

**结论**：**EXPOSED** — 对 `starts_at` 和 `ends_at` 均添加往返检查：
```php
$dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $raw);
if ($dt === false || $dt->format(DateTimeInterface::ATOM) !== $raw) {
    $errors[] = new ValidationError('starts_at', 'starts_at must be a valid ISO 8601 datetime.', 'invalid_format');
}
```

---

### V-08 — 聚合查询中的无界日期范围

**攻击**：请求跨越任意大日期范围的摘要，导致内存耗尽或慢查询。

```http
GET /summary/weekly?from=1900-01-01&to=2099-12-31
```

**观察结果**：查询在表中所有行上运行。数据量大时可能导致内存使用过多或多秒响应时间。

**结论**：**EXPOSED** — 在控制器层限制最大允许范围（例如 90 天）：
```php
$maxDays = 90;
$diff    = (new DateTimeImmutable($to))->diff(new DateTimeImmutable($from));
if ($diff->days > $maxDays) {
    return $this->json->create(['error' => "Date range must not exceed {$maxDays} days."], 422);
}
```

---

### V-09 — 无界员工姓名/角色长度

**攻击**：创建姓名或角色长达数万字符的员工。

```json
{"name": "AAAA... (50000 个字符)", "role": "Y", "hourly_rate": 10}
```

**观察结果**：`201 Created` — SQLite TEXT 无长度限制；行被插入。

**结论**：**EXPOSED** — 添加 `mb_strlen()` 检查并返回 422：
```php
if (mb_strlen($name) > 100) {
    $errors[] = new ValidationError('name', 'name must not exceed 100 characters.', 'max_length');
}
```

---

### V-10 — 无界地点字符串

**攻击**：安排一个地点字符串任意长的班次。

```json
{"employee_id": 1, "starts_at": "...", "ends_at": "...", "location": "BBBB... (50000 个字符)"}
```

**观察结果**：`201 Created` — 未强制长度限制。

**结论**：**EXPOSED** — 添加 `mb_strlen($location) <= 200` 检查。

---

### V-11 — 姓名/角色/地点中的 XSS 负载

**攻击**：在任意自由文本字段中存储 `<script>` 标签。

```json
{"name": "<script>alert(1)</script>", "role": "Admin", "hourly_rate": 1}
```

**观察结果**：`201 Created`。值在 JSON 响应中原样返回。

**结论**：**ACCEPTED BY DESIGN** — 这是一个 JSON API；转义是 HTML 渲染客户端的责任。服务器不从这些字段输出 HTML。在 OpenAPI 规范中记录此约定。

---

### V-12 — 路径 ID 为非数字

**攻击**：将非数字或负值作为 `{id}` 传入。

```http
GET /shifts/abc
GET /shifts/-1
DELETE /employees/0
```

**观察结果**：每种情况均返回 `404 Not Found`。`(int) "abc"` = `0`；不存在 ID 为 0 或负数的班次/员工，因此 `findShiftById(0)` 抛出 `ShiftNotFoundException`，处理器将其映射为 404。

**结论**：实践中 **BLOCKED**。注意：`(int) "9abc"` = `9` — 如果存在 ID 为 9 的记录，它会被返回。当差异重要时，使用 `ctype_digit()` 进行严格的路径 ID 校验。

---

## VULN 汇总

| # | 攻击向量 | 结论 |
|---|---------|------|
| V-01 | 无认证 | EXPOSED（设计选择） |
| V-02 | 无授权/任意班次可删除 | EXPOSED（设计选择） |
| V-03 | SQL 注入 | BLOCKED |
| V-04 | 重叠竞争条件 | BLOCKED |
| V-05 | ends_at ≤ starts_at | BLOCKED |
| V-06 | 负数 hourly_rate 绕过应用检查 | PARTIALLY EXPOSED |
| V-07 | 语义上无效的 ISO 8601 日期时间 | EXPOSED |
| V-08 | 聚合查询中的无界日期范围 | EXPOSED |
| V-09 | 无界员工姓名/角色 | EXPOSED |
| V-10 | 无界地点字符串 | EXPOSED |
| V-11 | XSS 负载存储 | ACCEPTED BY DESIGN |
| V-12 | 路径 ID 为非数字 | BLOCKED |

**生产前需修复的真实漏洞**：
1. **V-01/02** — 添加认证和基于角色的授权
2. **V-06** — 在应用层添加 `hourly_rate > 0` 校验
3. **V-07** — 对日期时间字段添加 ISO 8601 往返校验
4. **V-08** — 在聚合端点限制最大日期范围（例如 90 天）
5. **V-09/10** — 对所有自由文本字段添加 `mb_strlen()` 最大长度检查

---

## 相关指南

- [`notification-inbox.md`](notification-inbox.md) — IDOR 防护模式（未授权读写返回 404）
- [`prevent-double-booking.md`](prevent-double-booking.md) — 事务性双重预订防止
- [`expense-tracker.md`](expense-tracker.md) — ISO 8601 往返日期校验
- [`resource-booking.md`](resource-booking.md) — 日期范围限制和时间窗口查询
