# ハウツー: JWT リフレッシュトークンローテーション

このガイドでは、短命アクセストークンと長命リフレッシュトークンの組み合わせの実装について説明します。主要なプロパティは**ローテーション**です: リフレッシュトークンを使用するたびに即座に失効させて新しいものを発行します。再使用（既に失効した）リフレッシュトークンが届いた場合、そのユーザーのすべてのトークンの失効が発動されます。

---

## なぜ 2 つのトークン？

| トークン | TTL | 保存場所 | 目的 |
|---|---|---|---|
| アクセストークン | 5 分 | クライアントメモリ | API リクエストを認証する（ステートレス、DB ルックアップなし） |
| リフレッシュトークン | 7 日間 | DB（ハッシュ化） | 新しいアクセストークンを発行; ローテーションで管理 |

短命アクセストークンは漏洩した場合のダメージを制限します — 数分で期限切れになります。リフレッシュトークンは再ログインせずにセッションを延長しますが、データベースに存在するため失効させることができます。

---

## スキーマ

```sql
CREATE TABLE refresh_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    token_hash TEXT    NOT NULL UNIQUE,  -- SHA-256 ハッシュ; 生の値は保存しない
    expires_at TEXT    NOT NULL,
    revoked    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);
```

`token_hash` — 常にハッシュを保存し、生のトークンは保存しません。DB が漏洩しても、ハッシュ化されたトークンは直接使用できません。

---

## トークンの発行

### アクセストークン: 一意性のために `jti` を追加する

`jti` なしでは、同じユーザーに同じ秒に発行された 2 つのトークンが同一になります — ペイロードがバイト単位で等しい。`jti`（JWT ID）は各トークンを一意にし、将来のアクセストークンブロックリストの基盤となります:

```php
$accessToken = $this->issuer->issue([
    'jti'   => bin2hex(random_bytes(8)),  // 発行ごとに一意
    'sub'   => $user->id,
    'email' => $user->email,
    'iat'   => time(),
    'exp'   => time() + 300,  // 5 分
]);
```

### リフレッシュトークン: ハッシュを保存し、生の値を返す

```php
public function issue(int $userId): string
{
    $raw       = bin2hex(random_bytes(32));  // 256 ビットランダムトークン
    $hash      = hash('sha256', $raw);       // これのみを保存
    $expiresAt = (new \DateTimeImmutable())->modify('+7 days')->format('Y-m-d\TH:i:s\Z');
    $createdAt = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');

    $this->executor->insert(
        'INSERT INTO refresh_tokens (user_id, token_hash, expires_at, revoked, created_at)
         VALUES (?, ?, ?, 0, ?)',
        [$userId, $hash, $expiresAt, $createdAt],
    );

    return $raw;  // クライアントはこれを受け取る; DB にはこれを保存しない
}
```

クライアントが指定した値からトークンを検索するには:

```php
public function findByRaw(string $raw): ?RefreshToken
{
    $hash = hash('sha256', $raw);

    $row = $this->executor->fetchOne(
        'SELECT ... FROM refresh_tokens WHERE token_hash = ?',
        [$hash],
    );
    // ...
}
```

---

## トークンローテーション

すべてのリフレッシュリクエストは新しいものを発行する前に古いトークンを失効させる必要があります:

```php
private function refresh(ServerRequestInterface $request): ResponseInterface
{
    // ... ボディを解析し、保存されたトークンを見つける ...

    if ($stored === null || !$stored->isValid()) {
        // 失効したトークンの再使用は潜在的なリプレイ攻撃 —
        // 再認証を強制するためにユーザーのすべてのトークンを失効させる。
        if ($stored !== null && $stored->revoked) {
            $this->refreshTokens->revokeAllForUser($stored->userId);
        }

        return $this->problems->create(
            $request,
            'invalid-refresh-token',
            'Invalid or Expired Refresh Token',
            401,
            'The refresh token is invalid, expired, or has already been used.',
        );
    }

    $user = $this->users->findById($stored->userId);

    // ローテーション: まず古いトークンを失効させ、次に新しいペアを発行
    $this->refreshTokens->revoke($stored->id);

    return $this->json->create($this->issueTokenPair($user));
}
```

**再使用検出**: 失効したリフレッシュトークンが `/auth/refresh` エンドポイントに届いた場合、ユーザーが古いトークンをリプレイしている（珍しい）か、攻撃者がそれを盗んだことを意味します。`revokeAllForUser()` はすべてのセッションに再認証を強制し、被害範囲を制限します。

---

## ログアウト: 常に 204 を返す

リフレッシュトークンが有効かどうかに応じて異なるステータスコードを返すことはしないでください。そうすると攻撃者がトークンがまだアクティブかどうかを調べられます:

```php
private function logout(ServerRequestInterface $request): ResponseInterface
{
    // ... ボディを解析する ...

    $stored = $this->refreshTokens->findByRaw($body['refresh_token']);

    if ($stored !== null && !$stored->revoked) {
        $this->refreshTokens->revoke($stored->id);
    }

    // 常に 204 — トークンが有効かどうかを決して漏洩しない
    return $this->json->createEmpty(204);
}
```

これはまた、二重ログアウト（同じトークンで 2 回ログアウトを呼び出す）が両方とも 204 を返すことを意味します — クライアントはトークンの状態を心配せずに常に安全にログアウトを呼び出せます。

---

## RefreshToken エンティティの有効性チェック

```php
public function isValid(): bool
{
    if ($this->revoked) {
        return false;
    }

    return $this->expiresAt > (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
}
```

文字列比較は辞書順にソートされた ISO-8601 日付に有効です。タイムスタンプを Unix 整数として保存している場合は、代わりに `time()` と比較してください。

---

## BearerTokenMiddleware: リフレッシュ/ログアウトパスを除外する

リフレッシュとログアウトエンドポイントは Authorization ヘッダーの Bearer アクセストークンではなく、ボディのリフレッシュトークンを受け取ります。`BearerTokenMiddleware` からそれらを除外してください:

```php
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $verifier,
    excludedPaths: ['/auth/login', '/auth/refresh', '/auth/logout'],
);
```

`/auth/me` エンドポイント（および他の保護されたパス）はミドルウェアで保護されたままです。

---

## レスポンス形式

```json
{
  "access_token":  "eyJhbGci...",
  "token_type":    "Bearer",
  "expires_in":    300,
  "refresh_token": "a3f92c..."
}
```

`expires_in`（秒）により、クライアントはアクセストークンが期限切れになる前にプロアクティブなリフレッシュをスケジュールできます。失敗したリクエストの後にリフレッシュするという手順を避けられます。

---

## コードレビューチェックリスト

1. `token_hash` カラムは `hash('sha256', $raw)` を保存する — 生の値は保存しない
2. リフレッシュハンドラーで `issueTokenPair()` の前に `revoke()` が呼び出される
3. 失効トークンの再使用が `revokeAllForUser()` を発動させる（単なる 401 ではない）
4. ログアウトは常に 204 を返す — 条件付きの 401/404 なし
5. アクセストークン TTL が短い（15 分以下）
6. アクセストークンに `jti` クレームが存在する
7. テストがクロストークンローテーション（リフレッシュ後の古いトークンの無効化）と再使用検出をカバーしている

---

## ローテーションと再使用検出のテスト

```php
public function testRefreshTokenRotation_OldTokenIsInvalidAfterRefresh(): void
{
    $tokens = $this->login();

    $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);

    // 古いトークンは拒否されなければならない
    $res = $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
    $this->assertSame(401, $res->getStatusCode());
}

public function testRefreshTokenReuseRevokesAllUserTokens(): void
{
    $tokens = $this->login();

    // 1 回ローテーション — 古いトークンは失効済み
    $newTokens = $this->json($this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]));

    // 攻撃者が古い（失効済み）リフレッシュトークンをリプレイ — revokeAllForUser() が発動
    $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);

    // 新しく発行されたリフレッシュトークンも失効済み
    $res = $this->post('/auth/refresh', ['refresh_token' => (string) $newTokens['refresh_token']]);
    $this->assertSame(401, $res->getStatusCode());
}
```

---

## 関連情報

- `docs/howto/jwt-authentication.md` — JWT 発行、BearerTokenMiddleware、`nene2.auth.claims`
- `docs/howto/password-hashing.md` — Argon2id、ユーザー列挙防止のためのダミーハッシュパターン
- `docs/field-trials/2026-05-field-trial-113.md` — リフレッシュトークンローテーションフィールドトライアル
