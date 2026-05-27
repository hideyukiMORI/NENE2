# 操作指南：强制资源所有权（IDOR 防护）

不安全直接对象引用（IDOR）是 OWASP API 安全 Top 10 中排名第一的 API 漏洞。当用户可以通过猜测或枚举 ID 来访问或修改其他用户的资源时，就会发生 IDOR 攻击。

NENE2 不提供自动的所有权强制——每个仓库和处理器都必须显式实现。本指南展示推荐的模式。

---

## 1. 核心规则：返回 404，而非 403

当用户访问属于其他用户的资源时，返回 `404 Not Found`——**不要**返回 `403 Forbidden`。

- **403** 告诉攻击者："此资源存在，但你无法访问它。"——信息泄露
- **404** 告诉攻击者："此资源不存在。"——无任何确认

```php
// 错误——泄露存在性
if ($note->ownerId !== $authUserId) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403, '');
}

// 正确——不透露任何信息
if ($note === null) {
    return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
}
```

实现这一点的实用方法：让仓库**无法返回不属于调用者的资源**——见下一节。

---

## 2. 在 SQL 层面强制所有权

最安全的模式是在每个查询中都包含 `owner_id`。无论调用者如何使用结果，该方法在物理上都无法返回其他用户的数据。

```php
public function findByIdAndOwner(int $id, string $ownerId): ?Resource
{
    $row = $this->db->fetchOne(
        'SELECT * FROM resources WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    );
    return $row !== null ? $this->hydrate($row) : null;
}

public function update(int $id, string $ownerId, string $newValue): bool
{
    $updated = $this->db->execute(
        'UPDATE resources SET value = ? WHERE id = ? AND owner_id = ?',
        [$newValue, $id, $ownerId],
    );
    return $updated > 0;
}

public function delete(int $id, string $ownerId): bool
{
    return $this->db->execute(
        'DELETE FROM resources WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    ) > 0;
}
```

**为什么 SQL 层比应用层更好：**
- 应用层检查在开发者忘记调用时可能被绕过
- SQL 层检查无法跳过——错误所有者的行根本不会被返回
- "未找到"和"所有者错误"都返回 `null`，防止调用者意外地对他们不应该知道的情况进行分支处理

---

## 3. 处理器模式

```php
private function show(ServerRequestInterface $request): ResponseInterface
{
    $authUserId = $this->resolveAuthUser($request);
    if ($authUserId === null) {
        return $this->unauthorized($request);
    }

    $id       = $this->resolveId($request);
    $resource = $this->repo->findByIdAndOwner($id, $authUserId);

    if ($resource === null) {
        // 404 同时覆盖"未找到"和"属于其他用户"两种情况
        return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
    }

    return $this->json->create($resource->toArray());
}
```

---

## 4. 列表接口：在查询中按所有者过滤

```php
public function listByOwner(string $ownerId): array
{
    return $this->db->fetchAll(
        'SELECT * FROM resources WHERE owner_id = ? ORDER BY id DESC',
        [$ownerId],
    );
}
```

永远不要获取所有行再在 PHP 中过滤。如果过滤逻辑有误，会泄露其他用户的数据，同时也是 N+1 问题。

---

## 5. 明确测试跨所有者访问

添加专门测试，验证 IDOR 已被防护：

```php
public function testCannotReadAnotherUsersResource(): void
{
    $bobId = $this->decode($this->create('bob', 'Bob content'))['id'];

    // Alice 尝试读取 Bob 的资源——必须得到 404
    $res = $this->request('GET', '/resources/' . $bobId, authUser: 'alice');
    self::assertSame(404, $res->getStatusCode());
    // 明确不是 403——403 会泄露资源的存在性
    self::assertNotSame(403, $res->getStatusCode());
}

public function testListDoesNotLeakCrossTenantData(): void
{
    $this->create('alice', 'Alice content');
    $this->create('bob', 'Bob content');

    $aliceList = $this->decode($this->request('GET', '/resources', authUser: 'alice'));
    $titles    = array_column($aliceList['items'], 'content');

    self::assertNotContains('Bob content', $titles);
}
```

---

## 说明

- **为什么 404 感觉不对**：对 URL 中可见的资源返回 404 感觉"不诚实"。确实如此——但 OWASP 明确建议这样做以防止 ID 枚举攻击。这个权衡是被接受的安全实践。
- **管理员绕过**：如果有管理员路由可以查看任何资源，请将它们放在单独的路径前缀下并使用单独的所有权检查（或不检查）。不要用"是否是管理员"标志来复杂化所有权方法。
- **数据库索引**：始终在 `owner_id`（以及复合查找的 `(owner_id, id)`）上添加索引。没有索引，每个按用户的查询都是全表扫描。
