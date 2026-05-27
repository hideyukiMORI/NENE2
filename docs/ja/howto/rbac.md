# ハウツー: ロールベースアクセス制御（RBAC）

JWT クレームと `BearerTokenMiddleware` を使ったロールベースアクセス制御の実装。

---

## クイックスタート

```php
// 1. ログイン時に JWT に role を含める
$token = $issuer->issue([
    'sub'  => $user->id,
    'role' => $user->role->value,  // 'user' または 'admin'
    'exp'  => time() + 3600,
]);

// 2. ハンドラーでロールを確認する
/** @var array<string, mixed>|null $claims */
$claims     = $request->getAttribute('nene2.auth.claims');
$actualRole = Role::tryFrom((string) ($claims['role'] ?? ''));

if ($actualRole !== Role::Admin) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403,
        "This action requires the 'admin' role.");
}
```

---

## JWT クレームへのロールの埋め込み

**2 つのアプローチ:**

| アプローチ | メリット | デメリット |
|---|---|---|
| JWT クレームにロール | リクエストごとの DB クエリなし | ロール変更はトークンが期限切れになって初めて有効 |
| リクエストごとの DB ルックアップ | 即座のロール変更 | すべての認証済みリクエストで追加クエリ |

多くのアプリケーションでは JWT アプローチが適切です。高セキュリティのコンテキスト（医療、金融、管理者権限失効）では機密性の高い操作に DB ルックアップを追加してください。

```php
// ログイン — クレームにロールを埋め込む
$token = $issuer->issue([
    'sub'   => $user->id,
    'email' => $user->email,
    'role'  => $user->role->value,   // 文字列: 'user' | 'admin'
    'iat'   => time(),
    'exp'   => time() + 3600,
]);
```

---

## 401 Unauthorized と 403 Forbidden

この区別はクライアントのエラーハンドリングに重要です（401 → ログインにリダイレクト、403 → 権限エラーを表示）:

| 状況 | ステータス |
|---|---|
| トークンなし / 期限切れ / 無効な署名 | **401** Unauthorized |
| 有効なトークンだが不十分なロール | **403** Forbidden |
| リソースが見つからない | **404** Not Found |

```php
// ❌ 間違い — 認証済みユーザーが 401 を受け取る（「ログインしていない」を意味する）
if ($role !== Role::Admin) {
    return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, '...');
}

// ✅ 正しい — 認証済みだが権限がない
if ($role !== Role::Admin) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403,
        "This action requires the 'admin' role.");
}
```

---

## `requireAuth()` / `requireRole()` パターン

ルートレジストラーでの再利用可能なヘルパーペア:

```php
use Nene2\Auth\TokenVerificationException;
use Nene2\Auth\TokenVerifierInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly TokenVerifierInterface $verifier,
        // ... 他の依存関係
    ) {}

    /**
     * 成功時にクレームを返し、失敗時に 401 ResponseInterface を返します。
     * まずミドルウェア属性を確認し、BearerTokenMiddleware から除外されたパスは
     * 手動検証にフォールバックします。
     *
     * @return array<string, mixed>|ResponseInterface
     */
    private function requireAuth(ServerRequestInterface $request): array|ResponseInterface
    {
        /** @var array<string, mixed>|null $claims */
        $claims = $request->getAttribute('nene2.auth.claims');

        if (is_array($claims)) {
            return $claims;
        }

        $authorization = $request->getHeaderLine('Authorization');

        if ($authorization === '' || !str_starts_with($authorization, 'Bearer ')) {
            return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401,
                'Authentication required.');
        }

        try {
            return $this->verifier->verify(substr($authorization, 7));
        } catch (TokenVerificationException) {
            return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401,
                'Token is invalid or expired.');
        }
    }

    /**
     * ユーザーが必要なロールを持つ場合はクレームを返し、
     * そうでない場合は 401/403 ResponseInterface を返します。
     *
     * @return array<string, mixed>|ResponseInterface
     */
    private function requireRole(ServerRequestInterface $request, Role $required): array|ResponseInterface
    {
        $claims = $this->requireAuth($request);

        if ($claims instanceof ResponseInterface) {
            return $claims;
        }

        $actualRole = Role::tryFrom((string) ($claims['role'] ?? ''));

        if ($actualRole !== $required) {
            return $this->problems->create($request, 'forbidden', 'Forbidden', 403,
                "This action requires the '{$required->value}' role.");
        }

        return $claims;
    }
}
```

ハンドラーでの使用:

```php
private function deletePost(ServerRequestInterface $request): ResponseInterface
{
    $claims = $this->requireRole($request, Role::Admin);
    if ($claims instanceof ResponseInterface) {
        return $claims;  // 401 または 403
    }
    // $claims は検証済みの管理者の JWT ペイロード
}
```

---

## `BearerTokenMiddleware` は HTTP メソッドで区別しない

`BearerTokenMiddleware` は認証が必要かどうかを決めるために HTTP メソッドではなくリクエストパスを使用します。`GET /posts`（公開）と `POST /posts`（認証必要）が同じパスを共有する場合、ミドルウェアから `/posts` を除外してハンドラーで手動でトークンを検証します:

```php
// ミドルウェア: /posts を完全に除外（GET と POST の両方をカバー）
$auth = new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login', '/posts']);

// DELETE /posts/{id}（パス /posts/1、/posts/2 など）の場合 — excludedPaths にない → ミドルウェアが保護。
// POST /posts（パス /posts）の場合 — 除外されている → ハンドラーが手動で requireAuth() を呼び出す必要がある。
```

上記の `requireAuth()` ヘルパーがこれを透過的に処理します: 存在する場合はミドルウェア属性から `nene2.auth.claims` を読み取り、存在しない場合は直接 `Authorization` ヘッダーを解析するフォールバックを行います。

**代替案**: 曖昧さを完全に避けるために異なるパスプレフィックスを使用してください:
- `GET /public/posts` — 認証なし
- `POST /posts` — 認証必要（ミドルウェアが `/posts` を競合なく保護できる）

---

## `Role` enum パターン

型安全なロール処理のためにバックドエナムを使ってください:

```php
enum Role: string
{
    case User  = 'user';
    case Admin = 'admin';
}

// ❌ Role::from() は不明な値でスローする
$role = Role::from($claims['role']);  // 'superuser' または '' の場合 UnhandledMatchError

// ✅ Role::tryFrom() は不明な値に null を返す
$role = Role::tryFrom((string) ($claims['role'] ?? ''));
if ($role === null || $role !== Role::Admin) {
    return 403;
}
```

---

## 204 No Content — `createEmpty()` を使用する

`JsonResponseFactory::create()` は `array` 引数を要求します。ボディのない 204 レスポンスには `createEmpty()` を使ってください:

```php
// ❌ 型エラー — create() は null を受け付けない
return $this->json->create(null, 204);

// ❌ 空の JSON オブジェクト {} を返す（204 ではボディは存在すべきでない）
return $this->json->create([], 204);

// ✅ 正しい — ボディなし、正確なステータス
return $this->json->createEmpty(204);
```

---

## コードレビューチェックリスト

- [ ] `role` クレームは `Role::tryFrom()` でデコードされる（`Role::from()` ではない — 不明な値でスローする）
- [ ] 権限不十分には 403、未認証には 401 が返される（両方を 401 にしない）
- [ ] `requireRole()` も `requireAuth()` を呼び出す — 重複した認証チェック不要
- [ ] `BearerTokenMiddleware` の除外が理解されている: 除外されたパスはクレーム属性をバイパスする
- [ ] 除外されたパスのハンドラーは手動トークン検証で `requireAuth()` を呼び出す
- [ ] 204 レスポンスは `create(null, 204)` ではなく `createEmpty(204)` を使用する
- [ ] JWT ロールキャッシングが理解されている: ロール変更はトークン期限切れ後に有効になる
