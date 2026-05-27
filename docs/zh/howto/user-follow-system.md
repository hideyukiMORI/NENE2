# 使用 NENE2 构建用户关注系统

本指南介绍如何构建 Twitter/Instagram 风格的社交关注系统——用户相互关注、查看粉丝/关注列表，以及检查互相关注状态。

**字段试验**：FT134
**NENE2 版本**：^1.5
**涵盖主题**：关注/取消关注、幂等写入、图查询、软约束执行

---

## 我们要构建什么

一个允许用户进行以下操作的 REST API：

- 关注和取消关注其他用户（幂等关注，204 取消关注）
- 查询粉丝/关注列表（按最近优先排序）
- 通过一次统计调用获取粉丝数/关注数
- 检查特定用户是否正在关注另一用户

---

## 数据库结构

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE follows (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    follower_id INTEGER NOT NULL,
    followee_id INTEGER NOT NULL,
    created_at  TEXT    NOT NULL,
    UNIQUE (follower_id, followee_id),
    CHECK  (follower_id != followee_id),
    FOREIGN KEY (follower_id) REFERENCES users(id),
    FOREIGN KEY (followee_id) REFERENCES users(id)
);
```

两个约束在 DB 层承担主要工作：

- `UNIQUE (follower_id, followee_id)` — 防止重复关注记录
- `CHECK (follower_id != followee_id)` — 在 DB 层防止自关注（应用层也会验证）

---

## API 端点

| 方法 | 路径 | 说明 |
|---|---|---|
| `POST` | `/users` | 创建用户 |
| `POST` | `/users/{followerId}/follow` | 关注用户（幂等） |
| `DELETE` | `/users/{followerId}/follow/{followeeId}` | 取消关注用户 |
| `GET` | `/users/{userId}/stats` | 获取粉丝/关注数量 |
| `GET` | `/users/{userId}/followers` | 列出粉丝 |
| `GET` | `/users/{userId}/following` | 列出该用户关注的人 |
| `GET` | `/users/{followerId}/is-following/{followeeId}` | 检查关注关系 |

---

## Repository

```php
final class FollowRepository
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $executor,
    ) {}

    public function follow(int $followerId, int $followeeId, string $now): bool
    {
        if ($this->isFollowing($followerId, $followeeId)) {
            return false; // 已关注——返回 false，处理器发送 200
        }

        $this->executor->execute(
            'INSERT INTO follows (follower_id, followee_id, created_at) VALUES (?, ?, ?)',
            [$followerId, $followeeId, $now],
        );

        return true; // 新关注——处理器发送 201
    }

    public function unfollow(int $followerId, int $followeeId): bool
    {
        $count = $this->executor->execute(
            'DELETE FROM follows WHERE follower_id = ? AND followee_id = ?',
            [$followerId, $followeeId],
        );

        return $count > 0;
    }

    public function isFollowing(int $followerId, int $followeeId): bool
    {
        return $this->executor->fetchOne(
            'SELECT id FROM follows WHERE follower_id = ? AND followee_id = ?',
            [$followerId, $followeeId],
        ) !== null;
    }

    /** @return array<int, array{id: int, name: string}> */
    public function listFollowers(int $userId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT u.id, u.name FROM users u
             INNER JOIN follows f ON f.follower_id = u.id
             WHERE f.followee_id = ?
             ORDER BY f.id DESC',
            [$userId],
        );

        return array_map(fn(mixed $row) => $this->hydrateUser((array) $row), $rows);
    }

    /** @return array<int, array{id: int, name: string}> */
    public function listFollowing(int $userId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT u.id, u.name FROM users u
             INNER JOIN follows f ON f.followee_id = u.id
             WHERE f.follower_id = ?
             ORDER BY f.id DESC',
            [$userId],
        );

        return array_map(fn(mixed $row) => $this->hydrateUser((array) $row), $rows);
    }
}
```

`follow()` 方法返回 `bool` 以标识新关注还是已有关注，让处理器无需额外查询就能返回正确的 HTTP 状态。

---

## 关注处理器——幂等 POST

难点在于为新关注返回 **201 Created**，为重复关注返回 **200 OK**，同时以 422 拒绝自关注。

```php
private function followUser(ServerRequestInterface $request): ResponseInterface
{
    $params     = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $followerId = isset($params['followerId']) && is_numeric($params['followerId'])
        ? (int) $params['followerId'] : 0;

    if ($followerId <= 0 || !$this->repo->findUserById($followerId)) {
        return $this->responseFactory->create(['error' => 'follower not found'], 404);
    }

    $body       = JsonRequestBodyParser::parse($request);
    $followeeId = isset($body['followee_id']) && is_int($body['followee_id'])
        ? $body['followee_id'] : 0;

    if ($followeeId <= 0 || !$this->repo->findUserById($followeeId)) {
        return $this->responseFactory->create(['error' => 'followee not found'], 404);
    }

    if ($followerId === $followeeId) {
        return $this->responseFactory->create(['error' => 'cannot follow yourself'], 422);
    }

    $wasNew = $this->repo->follow($followerId, $followeeId, date('Y-m-d H:i:s'));
    $status = $wasNew ? 201 : 200;

    return $this->responseFactory->create([
        'follower_id' => $followerId,
        'followee_id' => $followeeId,
        'following'   => true,
    ], $status);
}
```

**为什么在自关注检查之前验证？** 先进行 404 检查，是为了让 `POST /users/9999/follow`（携带 `followee_id: 9999`）返回 404（用户不存在）而非 422（自关注）。存在性错误优先。

---

## 取消关注处理器——204 或 404

```php
private function unfollowUser(ServerRequestInterface $request): ResponseInterface
{
    $params     = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $followerId = isset($params['followerId']) && is_numeric($params['followerId'])
        ? (int) $params['followerId'] : 0;
    $followeeId = isset($params['followeeId']) && is_numeric($params['followeeId'])
        ? (int) $params['followeeId'] : 0;

    if ($followerId <= 0 || !$this->repo->findUserById($followerId)) {
        return $this->responseFactory->create(['error' => 'follower not found'], 404);
    }

    $removed = $this->repo->unfollow($followerId, $followeeId);

    if (!$removed) {
        return $this->responseFactory->create(['error' => 'not following'], 404);
    }

    return $this->responseFactory->createEmpty(204);
}
```

注意：`createEmpty(204)`——`JsonResponseFactory` 有一个专用方法用于无响应体的响应。

---

## 统计端点

```php
private function stats(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $userId = isset($params['userId']) && is_numeric($params['userId'])
        ? (int) $params['userId'] : 0;

    if ($userId <= 0 || !$this->repo->findUserById($userId)) {
        return $this->responseFactory->create(['error' => 'user not found'], 404);
    }

    return $this->responseFactory->create([
        'user_id'         => $userId,
        'followers_count' => $this->repo->followerCount($userId),
        'following_count' => $this->repo->followingCount($userId),
    ]);
}
```

---

## AppFactory

```php
final class AppFactory
{
    public static function createSqliteApp(string $dbPath): RequestHandlerInterface
    {
        $dbConfig = new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'sqlite',
            host: '',
            port: 1,
            name: $dbPath,
            user: '',
            password: '',
            charset: '',
        );

        $factory  = new PdoConnectionFactory($dbConfig);
        $executor = new PdoDatabaseQueryExecutor($factory);
        $psr17    = new Psr17Factory();
        $json     = new JsonResponseFactory($psr17, $psr17);

        $repo      = new FollowRepository($executor);
        $registrar = new RouteRegistrar($repo, $json);

        return new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn(Router $r) => $registrar->register($r)],
        )->create();
    }
}
```

---

## 测试策略

每个测试都在全新的内存 SQLite 文件上运行，在 `setUp()` 中创建，在 `tearDown()` 中删除。

关键用例：

```php
// 幂等关注 → 200
public function testFollowIdempotentReturns200(): void
{
    $alice = $this->createUser('Alice');
    $bob   = $this->createUser('Bob');

    $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
    $res = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);

    $this->assertSame(200, $res->getStatusCode());
    $this->assertTrue($this->json($res)['following']);
}

// 自关注 → 422
public function testFollowSelfReturns422(): void
{
    $alice = $this->createUser('Alice');
    $res   = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $alice]);
    $this->assertSame(422, $res->getStatusCode());
}

// 取消关注后再次关注 → 再次得到 201
public function testUnfollowThenRefollow(): void
{
    $alice = $this->createUser('Alice');
    $bob   = $this->createUser('Bob');

    $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
    $this->request('DELETE', "/users/{$alice}/follow/{$bob}");
    $res = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);

    $this->assertSame(201, $res->getStatusCode());
}

// 互相关注
public function testMutualFollow(): void
{
    $alice = $this->createUser('Alice');
    $bob   = $this->createUser('Bob');

    $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
    $this->request('POST', "/users/{$bob}/follow", ['followee_id' => $alice]);

    $this->assertTrue($this->json($this->request('GET', "/users/{$alice}/is-following/{$bob}"))['following']);
    $this->assertTrue($this->json($this->request('GET', "/users/{$bob}/is-following/{$alice}"))['following']);
}
```

---

## 排序约定

`listFollowers` 和 `listFollowing` 都使用 `ORDER BY f.id DESC`——最近关注的用户排在最前面。这与 Twitter/Instagram 的"粉丝"标签页行为一致。

---

## 常见陷阱

| 陷阱 | 修复方案 |
|---|---|
| 在自关注检查中对未知用户返回 422 | 在自关注检查*之前*验证用户存在性 |
| 从路径而非请求体读取 `followee_id` | `followee_id` 在请求体中；`followeeId` 是 DELETE 的路径参数 |
| 使用 `createJson()` | 正确方法是 `create(array $data, int $status = 200)` |
| 对带有响应体的 200 使用 `createEmpty()` | `createEmpty()` 仅用于 204 No Content |
| 缺少 `JsonRequestBodyParser::parse()` 调用 | 每个读取 JSON 请求体的处理器都必须手动调用此方法 |
