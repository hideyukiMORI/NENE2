# ハウツー: NENE2 でユーザーフォローシステムを構築する

このガイドでは、Twitter/Instagram スタイルのソーシャルフォローシステムを構築する方法を説明します — ユーザーがお互いをフォローし、フォロワー/フォロー中リストを表示し、相互フォロー状態を確認します。

**フィールドトライアル**: FT134
**NENE2 バージョン**: ^1.5
**扱うトピック**: フォロー/アンフォロー、冪等書き込み、グラフクエリ、ソフト制約の強制

---

## 何を構築するか

ユーザーが以下を行える REST API:

- 他のユーザーをフォロー/アンフォローする（冪等フォロー、204 アンフォロー）
- フォロワー/フォロー中リストをクエリする（最新順）
- 1 回の stat コールでフォロワー/フォロー中数を確認する
- 特定のユーザーが別のユーザーをフォローしているか確認する

---

## データベーススキーマ

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

2 つの制約が DB レイヤーで重要な役割を果たします:

- `UNIQUE (follower_id, followee_id)` — フォロー行の重複を防ぐ
- `CHECK (follower_id != followee_id)` — DB レベルで自分自身のフォローを防ぐ（アプリケーションも検証する）

---

## API エンドポイント

| メソッド | パス | 説明 |
|--------|------|------|
| `POST`   | `/users`                                        | ユーザーを作成する              |
| `POST`   | `/users/{followerId}/follow`                    | ユーザーをフォローする（冪等）  |
| `DELETE` | `/users/{followerId}/follow/{followeeId}`       | ユーザーをアンフォローする      |
| `GET`    | `/users/{userId}/stats`                         | フォロワー/フォロー中数を取得する |
| `GET`    | `/users/{userId}/followers`                     | フォロワーを一覧表示する        |
| `GET`    | `/users/{userId}/following`                     | フォロー中ユーザーを一覧表示する |
| `GET`    | `/users/{followerId}/is-following/{followeeId}` | フォロー関係を確認する          |

---

## リポジトリ

```php
final class FollowRepository
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $executor,
    ) {}

    public function follow(int $followerId, int $followeeId, string $now): bool
    {
        if ($this->isFollowing($followerId, $followeeId)) {
            return false; // すでにフォロー中 — ハンドラーが 200 を送るよう false を返す
        }

        $this->executor->execute(
            'INSERT INTO follows (follower_id, followee_id, created_at) VALUES (?, ?, ?)',
            [$followerId, $followeeId, $now],
        );

        return true; // 新規フォロー — ハンドラーが 201 を送る
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

`follow()` メソッドは `bool` を返して新規か既存かを示し、ハンドラーが余分なクエリなしで正しい HTTP ステータスを発行できるようにします。

---

## フォローハンドラー — 冪等 POST

難しい部分は、新規フォローに **201 Created**、再フォローに **200 OK** を返しながら、自己フォローを 422 で拒否することです。

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

**なぜ自己フォローチェックの前にバリデーションするのか?** 404 チェックが先に来ることで、`followee_id: 9999` の `POST /users/9999/follow` が 422（自己フォロー）ではなく 404（ユーザーが存在しない）を返します。存在エラーが優先されます。

---

## アンフォローハンドラー — 204 または 404

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

注意: `createEmpty(204)` — `JsonResponseFactory` はボディなしレスポンス用の専用メソッドを持っています。

---

## 統計エンドポイント

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

## テスト戦略

各テストは `setUp()` で作成され `tearDown()` で削除される新鮮なインメモリ SQLite ファイルに対して実行されます。

主要なケース:

```php
// 冪等フォロー → 200
public function testFollowIdempotentReturns200(): void
{
    $alice = $this->createUser('Alice');
    $bob   = $this->createUser('Bob');

    $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
    $res = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);

    $this->assertSame(200, $res->getStatusCode());
    $this->assertTrue($this->json($res)['following']);
}

// 自己フォロー → 422
public function testFollowSelfReturns422(): void
{
    $alice = $this->createUser('Alice');
    $res   = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $alice]);
    $this->assertSame(422, $res->getStatusCode());
}

// アンフォロー後に再フォロー → 再び 201
public function testUnfollowThenRefollow(): void
{
    $alice = $this->createUser('Alice');
    $bob   = $this->createUser('Bob');

    $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
    $this->request('DELETE', "/users/{$alice}/follow/{$bob}");
    $res = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);

    $this->assertSame(201, $res->getStatusCode());
}

// 相互フォロー
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

## 並び順の規約

`listFollowers` と `listFollowing` の両方が `ORDER BY f.id DESC` を使用します — 最近フォローしたユーザーが最初に表示されます。これは Twitter/Instagram の「フォロワー」タブの動作を反映しています。

---

## よくある落とし穴

| 落とし穴 | 修正方法 |
|---|---|
| 自己フォローチェックで未知のユーザーに 422 を返す | 自己フォローチェックの*前*にユーザーの存在を検証する |
| パスから `followee_id` を読み取る | `followee_id` はリクエストボディにある; `followeeId` は DELETE のパスパラメーター |
| `createJson()` を使用する | メソッドは `create(array $data, int $status = 200)` |
| ボディ付き 200 に `createEmpty()` を使用する | `createEmpty()` は 204 No Content にのみ使用する |
| `JsonRequestBodyParser::parse()` の呼び出しを忘れる | JSON ボディを読み取るすべてのハンドラーで手動で呼び出す必要がある |
