# 操作指南：服务状态页 API

> **NENE2 字段试验 185** — 组件健康状态跟踪、故障生命周期管理、
> 使用 `V::secret()` + `hash_equals()` 的管理员密钥保护。

---

## 本次试验验证内容

服务状态页 API 需要：
1. **组件状态跟踪** — operational / degraded / partial_outage / major_outage
2. **故障生命周期** — investigating → identified → monitoring → resolved
3. **不可变性守护** — 已解决的故障不能再修改（防止重新开启）
4. **管理员密钥保护** — `V::secret()` 强制写操作使用恒定时间比较
5. **状态枚举强制** — `V::enum()` 白名单防止注入未知值

---

## API

| 方法 | 路径 | 认证 | 说明 |
|---|---|---|---|
| `GET` | `/components` | — | 列出所有组件（公开） |
| `POST` | `/components` | X-Admin-Key | 创建组件 |
| `PATCH` | `/components/{id}` | X-Admin-Key | 更新组件状态 |
| `GET` | `/incidents` | — | 列出故障（公开，`?open=1` 仅活跃） |
| `GET` | `/incidents/{id}` | — | 故障详情及更新时间线 |
| `POST` | `/incidents` | X-Admin-Key | 创建故障 |
| `PATCH` | `/incidents/{id}` | X-Admin-Key | 更新故障状态 |
| `POST` | `/incidents/{id}/updates` | X-Admin-Key | 添加更新消息 |

---

## 核心模式：使用 `V::secret()` 的管理员密钥认证

```php
// V::secret() 检查：$expected !== '' && hash_equals($expected, $actual)
private function requireAdmin(ServerRequestInterface $request): bool
{
    return V::secret($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
}

// 每个写处理器中的用法：
if (!$this->requireAdmin($request)) {
    return $this->responseFactory->create(['error' => 'X-Admin-Key is required.'], 401);
}
```

**为什么用 `V::secret()` 而不是 `=== $key`：**
- `===` 是短路比较：比较时间随匹配长度变化 → 时序预言机漏洞
- `hash_equals()` 无论字符串在哪里不同都是恒定时间
- `$expected !== ''` 守护防止意外接受空密钥

---

## 使用 `V::enum()` 的状态枚举强制

```php
// V::enum(mixed $raw, string $enumClass): ?\BackedEnum
// 传入类名 — 返回类型化枚举实例或 null

$statusEnum = V::enum($body['status'] ?? null, ComponentStatus::class);

if (!$statusEnum instanceof ComponentStatus) {
    return $this->responseFactory->create(
        ['error' => 'status must be one of: ' . implode(', ', ComponentStatus::values()) . '.'],
        422,
    );
}

// $statusEnum 已经是正确的类型化枚举——无需 ::from()
$component = $this->repository->updateComponentStatus($id, $statusEnum);
```

**为什么枚举强制很重要：**
- 没有它，任意字符串会进入数据库
- 阻止 SQL `ORDER BY status` 注入向量
- 白名单就是枚举自身的 case——始终保持同步

---

## 故障生命周期与转换守护

```php
enum IncidentStatus: string
{
    case Investigating = 'investigating';
    case Identified    = 'identified';
    case Monitoring    = 'monitoring';
    case Resolved      = 'resolved';

    public function isResolved(): bool
    {
        return $this === self::Resolved;
    }
}
```

**每个写处理器中的转换守护：**
```php
$incident = $this->repository->findIncidentById($id);

// 已解决的故障是不可变的——防止意外重新开启
if ($incident->status->isResolved()) {
    return $this->responseFactory->create(
        ['error' => 'Resolved incidents cannot be updated.'],
        409,
    );
}
```

**为什么用 409（冲突）而不是 422（不可处理）：**
- 请求在语法上是有效的
- 冲突发生在资源的当前状态上
- 409 传达的是"有效请求，时机不对"

---

## 组件状态值

```php
enum ComponentStatus: string
{
    case Operational   = 'operational';    // 全部正常
    case Degraded      = 'degraded';       // 性能降级
    case PartialOutage = 'partial_outage'; // 部分功能不可用
    case MajorOutage   = 'major_outage';   // 完全服务故障
}
```

---

## 自动设置 `resolved_at` 时间戳

```php
public function updateIncidentStatus(int $id, IncidentStatus $status): ?Incident
{
    $now        = $this->now();
    $resolvedAt = $status->isResolved() ? $now : null;

    $stmt = $this->pdo->prepare(
        'UPDATE incidents SET status = :status, resolved_at = :resolved_at, updated_at = :now WHERE id = :id'
    );
    $stmt->execute(['status' => $status->value, 'resolved_at' => $resolvedAt, ...]);
}
```

`resolved_at` 时间戳由服务器设置——绝不从请求体获取。

---

## 整数 ID 解析（无注入）

```php
private function parseId(ServerRequestInterface $request, string $param): ?int
{
    $raw = Router::param($request, $param);

    // ctype_digit：拒绝负数、浮点数、字符串、路径遍历
    if ($raw === null || !ctype_digit($raw)) {
        return null;
    }

    $id = (int) $raw;

    return $id > 0 ? $id : null; // 同时拒绝零
}
```

---

## 活跃故障过滤

```php
// ?open=1 过滤掉已解决的故障
$openOnly = isset($params['open']) && $params['open'] === '1';

if ($openOnly) {
    $stmt = $pdo->prepare(
        "SELECT * FROM incidents WHERE status != 'resolved' ORDER BY created_at DESC"
    );
} else {
    $stmt = $pdo->query('SELECT * FROM incidents ORDER BY created_at DESC');
}
```

---

## 完整故障生命周期示例

```
POST /incidents          → 201 {status: "investigating", impact: "major"}
POST /incidents/1/updates → 201 {message: "Root cause identified."}
PATCH /incidents/1       → 200 {status: "identified"}
PATCH /incidents/1       → 200 {status: "monitoring"}
PATCH /incidents/1       → 200 {status: "resolved", resolved_at: "2026-05-26T..."}
PATCH /incidents/1       → 409 Resolved incidents cannot be updated.
GET /incidents?open=1    → 200 {count: 0}  — 已解决的故障不再显示
```

---

## 测试结果

```
46 tests / 93 assertions — 全部 PASS
PHPStan level 8 — 无错误
PHP CS Fixer — 干净
```

---

## 关键要点

| 模式 | 规则 |
|---|---|
| 管理员密钥认证 | `V::secret()` — 恒定时间 `hash_equals()`，守护空密钥 |
| 枚举校验 | `V::enum($raw, EnumClass::class)` — 返回类型化枚举或 null |
| 转换守护 | 应用变更前检查当前状态——已解决时返回 409 |
| `resolved_at` | 服务器设置的时间戳，绝不从请求体获取 |
| 整数 ID | `ctype_digit()` + `> 0` 守护——拒绝字符串、负数、零 |
| 公开读取 | GET 端点无需认证——状态页本来就是公开的 |
| 不可变历史 | 故障更新是仅追加的——不支持编辑/删除 |

完整示例：[`../NENE2-FT/statuslog/`](https://github.com/hideyukiMORI/NENE2-examples)（示例仓库）。
