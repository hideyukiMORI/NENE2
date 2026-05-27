# 操作指南：使用 NENE2 构建私信系统

> **FT 参考**：FT278（`NENE2-FT/messagelog`）——私信系统：会话线程化，UNIQUE(initiator_id, recipient_id) + CHECK(initiator_id != recipient_id)，仅参与者可访问，方向无关的查找，幂等会话创建，31 个测试 / 96 个断言全部通过。
>
> 另在 FT135 中进行了验证——原始实现。

本指南演示如何构建 Twitter/Instagram 风格的私信（DM）系统——用户互相开始会话、发送消息，只有会话参与者才能读取或发送消息。

**NENE2 版本**：^1.5
**涵盖主题**：会话线程化、参与者访问控制、方向无关的会话查找、幂等会话创建

---

## 我们要构建什么

一个 REST API，其中：

- 任意两个用户可以开始会话（幂等——重复创建返回已有会话）
- 只有参与者才能发送消息或读取会话消息
- 用户可以列出自己的会话（但不能查看他人的）
- 消息在会话内按从旧到新排列

---

## 数据库结构

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE conversations (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    initiator_id INTEGER NOT NULL,
    recipient_id INTEGER NOT NULL,
    created_at   TEXT    NOT NULL,
    UNIQUE (initiator_id, recipient_id),
    CHECK  (initiator_id != recipient_id),
    FOREIGN KEY (initiator_id) REFERENCES users(id),
    FOREIGN KEY (recipient_id) REFERENCES users(id)
);

CREATE TABLE messages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER NOT NULL,
    sender_id       INTEGER NOT NULL,
    content         TEXT    NOT NULL,
    created_at      TEXT    NOT NULL,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id),
    FOREIGN KEY (sender_id)       REFERENCES users(id)
);
```

`UNIQUE (initiator_id, recipient_id)` 约束强制每个有序对只有一个会话。应用层处理反向方向（Bob→Alice 返回与 Alice→Bob 相同的会话）。

---

## API 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| POST | `/users` | 创建用户 |
| POST | `/conversations` | 开始会话（幂等） |
| POST | `/conversations/{id}/messages` | 发送消息（仅参与者） |
| GET | `/conversations/{id}/messages` | 读取消息（仅参与者，通过 X-User-Id） |
| GET | `/users/{userId}/conversations` | 列出用户的会话（仅本人，通过 X-User-Id） |

---

## 方向无关的会话查找

核心挑战：Alice 和 Bob 开始会话（initiator=Alice，recipient=Bob）。之后 Bob 也和 Alice 开始一个。他们应该得到同一个会话，而不是两个独立的会话。

```php
public function findConversation(int $userA, int $userB): ?int
{
    $row = $this->executor->fetchOne(
        'SELECT id FROM conversations
         WHERE (initiator_id = ? AND recipient_id = ?)
            OR (initiator_id = ? AND recipient_id = ?)',
        [$userA, $userB, $userB, $userA],
    );

    if ($row === null) {
        return null;
    }

    $arr = (array) $row;

    return isset($arr['id']) ? (int) $arr['id'] : null;
}

public function findOrCreateConversation(int $initiatorId, int $recipientId, string $now): int
{
    $existing = $this->findConversation($initiatorId, $recipientId);

    if ($existing !== null) {
        return $existing;
    }

    $this->executor->execute(
        'INSERT INTO conversations (initiator_id, recipient_id, created_at) VALUES (?, ?, ?)',
        [$initiatorId, $recipientId, $now],
    );

    return (int) $this->executor->lastInsertId();
}
```

---

## 参与者检查

读取消息或发送前，验证调用者是否在会话中：

```php
public function isParticipant(int $conversationId, int $userId): bool
{
    return $this->executor->fetchOne(
        'SELECT id FROM conversations
         WHERE id = ? AND (initiator_id = ? OR recipient_id = ?)',
        [$conversationId, $userId, $userId],
    ) !== null;
}
```

---

## 调用者身份——X-User-Id 头

受保护端点使用简单的 `X-User-Id` 头来识别调用者。生产系统应改用 JWT 声明。

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');

    return is_numeric($header) ? (int) $header : 0;
}
```

**注意**：`is_numeric()` 对非数字字符串返回 false，因此 `X-User-Id: admin` → `actorId = 0` → 404。

---

## 发送消息处理器

```php
private function sendMessage(ServerRequestInterface $request): ResponseInterface
{
    $params         = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $conversationId = isset($params['conversationId']) && is_numeric($params['conversationId'])
        ? (int) $params['conversationId'] : 0;

    if ($conversationId <= 0 || $this->repo->findConversationById($conversationId) === null) {
        return $this->responseFactory->create(['error' => 'conversation not found'], 404);
    }

    $body     = JsonRequestBodyParser::parse($request);
    $senderId = isset($body['sender_id']) && is_int($body['sender_id']) ? $body['sender_id'] : 0;
    $content  = isset($body['content']) && is_string($body['content']) ? trim($body['content']) : '';

    if ($senderId <= 0 || !$this->repo->findUserById($senderId)) {
        return $this->responseFactory->create(['error' => 'sender not found'], 404);
    }

    if (!$this->repo->isParticipant($conversationId, $senderId)) {
        return $this->responseFactory->create(['error' => 'not a participant'], 403);
    }

    if ($content === '') {
        return $this->responseFactory->create(['error' => 'content is required'], 422);
    }

    $now       = date('Y-m-d H:i:s');
    $messageId = $this->repo->sendMessage($conversationId, $senderId, $content, $now);

    return $this->responseFactory->create([...], 201);
}
```

**检查顺序**：会话存在 → 发送者存在 → 发送者是参与者 → 内容有效。存在性检查在访问控制检查之前，防止信息泄露。

---

## 读取消息处理器——无请求体的 GET

对于需要身份识别的 GET 端点（`listMessages`、`listUserConversations`），调用者身份来自 `X-User-Id` 头。**不要在 GET 请求上调用 `JsonRequestBodyParser::parse()`**——因为 GET 请求没有 JSON 请求体，解析器会返回 400。

```php
private function listMessages(ServerRequestInterface $request): ResponseInterface
{
    $params         = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $conversationId = isset($params['conversationId']) && is_numeric($params['conversationId'])
        ? (int) $params['conversationId'] : 0;

    if ($conversationId <= 0 || $this->repo->findConversationById($conversationId) === null) {
        return $this->responseFactory->create(['error' => 'conversation not found'], 404);
    }

    // 此处不调用 JsonRequestBodyParser::parse()——调用者身份仅来自头部
    $actorId = $this->resolveActorId($request);

    if ($actorId <= 0 || !$this->repo->findUserById($actorId)) {
        return $this->responseFactory->create(['error' => 'actor not found'], 404);
    }

    if (!$this->repo->isParticipant($conversationId, $actorId)) {
        return $this->responseFactory->create(['error' => 'not a participant'], 403);
    }

    $messages = $this->repo->listMessages($conversationId);

    return $this->responseFactory->create(['items' => $messages, 'count' => count($messages)]);
}
```

---

## 消息排序

消息使用 `ORDER BY id ASC`——从旧到新，符合聊天 UI 的惯例。关注/通知列表使用 `ORDER BY id DESC`（从新到旧）。根据 UI 预期选择排序方向。

---

## 漏洞评估（FT135）

十二项漏洞测试验证：

| ID | 攻击 | 预期结果 | 测试结果 |
|----|------|---------|---------|
| VULN-A | 读取他人会话的消息（IDOR） | 403 | 通过 |
| VULN-B | 向非参与会话发送消息（IDOR） | 403 | 通过 |
| VULN-C | 读取他人会话列表（IDOR） | 403 | 通过 |
| VULN-D | 列出消息时缺少 X-User-Id | 404/403 | 通过 |
| VULN-E | 获取会话列表时缺少 X-User-Id | 403 | 通过 |
| VULN-F | 路径中的负数用户 ID | 404 | 通过 |
| VULN-G | 路径中的零会话 ID | 404 | 通过 |
| VULN-H | 非数字 X-User-Id 头 | 非 200 | 通过 |
| VULN-I | 消息内容中的 SQL 注入 | 201（原样存储） | 通过 |
| VULN-J | 消息内容中的 XSS | 201（原样存储） | 通过 |
| VULN-K | 尝试自我会话 | 422 | 通过 |
| VULN-L | 100KB 消息内容 | 201 或 413 | 通过 |

全部 12 项漏洞测试通过。未发现漏洞。

---

## 常见陷阱

| 陷阱 | 修复方法 |
|------|---------|
| 在 GET 请求上调用 `JsonRequestBodyParser::parse()` | 仅在期望请求体的 POST/PUT/PATCH 处理器中调用 |
| `UNIQUE (initiator_id, recipient_id)` 无法阻止 A→B 和 B→A 成为两个会话 | 在 INSERT 前用 OR 查询进行方向无关的查找 |
| 先检查内容有效性再检查参与者资格 | 先检查参与者，避免泄露信息 |
| 不验证用户是否存在，接受任何非零整数作为调用者 ID | 在检查参与者资格前，始终验证 `findUserById(actorId)` |

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 以有方向性的方式存储会话（user_a, user_b），A→B 和 B→A 存为独立两行 | 同两用户间积累重复会话；方向无关查找失效 |
| 无 `CHECK (initiator_id != recipient_id)` 约束 | 用户可以给自己发消息，产生混乱的自我会话 |
| 无 `UNIQUE (initiator_id, recipient_id)` 约束 | 并发创建会话请求为同一对用户产生重复行 |
| 非参与者访问时返回 404 而非 403 | 向非参与者泄露会话 ID 是否存在 |
| 在 GET `/conversations/{id}/messages` 上调用 `JsonRequestBodyParser::parse()` | GET 请求无请求体；解析器返回 400 |
| 先检查内容有效性再检查参与者资格 | 泄露信息——攻击者可通过发送空内容并观察 403 与 422 的差异来探测有效的会话 ID |
| 使用 `is_numeric()` 而不转换为 `int` 再验证 `> 0` | `is_numeric("0")` 为 true；用户 ID 0 会被当作有效值 |
| 参与者检查后跳过用户存在性检查 | `isParticipant()` 仅检查外键——如果数据库没有级联删除，被删除或不存在的用户仍可能出现 |
| 允许任意用户列出他人的会话 | IDOR——始终在返回会话列表前验证 `actorId === targetUserId` |
| 消息索引仅覆盖 `conversation_id` | 缺少 `id ASC` 索引导致大量消息历史的 ORDER BY 变慢 |
