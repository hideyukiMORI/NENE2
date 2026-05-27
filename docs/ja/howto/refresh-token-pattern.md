# ハウツー: リフレッシュトークンパターン

> **FT リファレンス**: FT281 (`NENE2-FT/refreshlog`) — リフレッシュトークンパターン: 短命アクセストークン（5 分 JWT）+ 長命リフレッシュトークン（7 日間）、SHA-256 ハッシュ保存、使用時のトークンローテーション、リプレイ攻撃検出（失効トークン → 全トークン失効）、ログアウトは常に 204 を返す、15 テスト / 63 アサーション PASS。

このガイドでは、リフレッシュトークンパターンの実装方法を解説します — セキュリティのための短命アクセストークンと、セッション継続性のためのリフレッシュトークン。

## なぜ重要か

JWT はステートレスです。一度発行されると、有効期限が切れるまで失効させることができません。5 分の TTL はトークンが盗まれた場合の影響を制限します。リフレッシュトークンはパスワードの再入力なしにセッションを延長し、各使用時にローテーション（失効して再発行）して盗難を検出できます。

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    token_hash TEXT    NOT NULL UNIQUE,
    expires_at TEXT    NOT NULL,
    revoked    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);
```

`token_hash` は生のトークンの SHA-256 ハッシュを保存します — 生の値は保存しません。`revoked` はリプレイ検出のためのソフトデリートフラグです（物理削除と対比して）。

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/auth/login` | なし | メール + パスワード → アクセストークン + リフレッシュトークン |
| `POST` | `/auth/refresh` | ボディ内リフレッシュトークン | リフレッシュトークンをローテーションし、新しいペアを発行する |
| `POST` | `/auth/logout` | ボディ内リフレッシュトークン | リフレッシュトークンを失効させる |
| `GET` | `/auth/me` | Bearer アクセストークン | 現在のユーザー情報を取得する |

## トークンの有効期間

```php
private const int ACCESS_TOKEN_TTL_SECONDS = 300; // 5 分 — セキュリティのために短い
// リフレッシュトークン: 7 日間（RefreshTokenRepository::TTL_DAYS）
```

短いアクセストークンは盗まれた場合の影響を制限します。長いリフレッシュトークンによりパスワードを再入力せずにセッションをまたいでログイン状態を保てます。

## トークンペアの発行

```php
private function issueTokenPair(User $user): array
{
    $now         = time();
    $accessToken = $this->issuer->issue([
        'jti'   => bin2hex(random_bytes(8)),  // 一意のトークン ID — 将来の失効追跡に使用
        'sub'   => $user->id,
        'email' => $user->email,
        'iat'   => $now,
        'exp'   => $now + self::ACCESS_TOKEN_TTL_SECONDS,
    ]);

    $refreshToken = $this->refreshTokens->issue($user->id);

    return [
        'access_token'  => $accessToken,
        'token_type'    => 'Bearer',
        'expires_in'    => self::ACCESS_TOKEN_TTL_SECONDS,
        'refresh_token' => $refreshToken,
    ];
}
```

## ハッシュのみを保存する

```php
public function issue(int $userId): string
{
    $raw       = bin2hex(random_bytes(32));  // 64 hex 文字 = 256 ビットエントロピー
    $hash      = hash('sha256', $raw);
    // INSERT token_hash = $hash ...

    return $raw;  // ← 生の値をクライアントに返す; 保存しない
}

public function findByRaw(string $raw): ?RefreshToken
{
    $hash = hash('sha256', $raw);
    // SELECT WHERE token_hash = $hash
}
```

DB が侵害された場合、攻撃者はハッシュを取得します — クライアントが保持する生のトークンなしでは役に立ちません。

## トークンローテーション

```php
// /auth/refresh の成功時:
$this->refreshTokens->revoke($stored->id);      // 古いトークンを失効
return $this->json->create($this->issueTokenPair($user));  // 新しいペアを発行
```

各リフレッシュでトークンがローテーションされます。古いトークンは即座に無効になるため、盗まれたリフレッシュトークンはローテーションが無効化する前に 1 回しか使用できません。

## リプレイ攻撃検出

```php
$stored = $this->refreshTokens->findByRaw($body['refresh_token']);

if ($stored === null || !$stored->isValid()) {
    // 失効したトークンの再使用 → 潜在的なリプレイ攻撃
    if ($stored !== null && $stored->revoked) {
        $this->refreshTokens->revokeAllForUser($stored->userId);
    }
    return $this->problems->create($request, 'invalid-refresh-token', '...', 401);
}
```

攻撃者がリフレッシュトークンを盗んで使用し、その後正規のクライアントがそれ（今は失効済み）を使用しようとする場合 — システムがこれを検出してユーザーの全セッションを失効させ、再認証を強制します。

## ログアウトは常に 204 を返す

```php
private function logout(ServerRequestInterface $request): ResponseInterface
{
    $stored = $this->refreshTokens->findByRaw($body['refresh_token']);

    if ($stored !== null && !$stored->revoked) {
        $this->refreshTokens->revoke($stored->id);
    }

    // 常に 204 — トークンが有効かどうかを決して明かさない
    return $this->json->createEmpty(204);
}
```

既に失効したトークンでのログアウト時に 401 を返すと、攻撃者が自分がログアウトされているかどうかを調べられます。

## トークン有効性チェック

```php
public function isValid(): bool
{
    if ($this->revoked) {
        return false;
    }
    return $this->expiresAt > (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
}
```

失効と有効期限の両方がチェックされます。期限切れだが失効していないトークンも拒否されます。

## セキュリティプロパティのサマリー

| プロパティ | 実装 |
|---|---|
| アクセストークン TTL | 5 分（盗難の影響を最小化） |
| リフレッシュトークン TTL | 7 日間（セッション継続性） |
| トークン保存 | SHA-256 ハッシュのみ; 生の値は保存しない |
| トークンローテーション | 各リフレッシュ成功時に古いトークンを失効 |
| リプレイ検出 | 失効トークンの再使用 → ユーザーの全セッションを失効 |
| ログアウト | 常に 204（トークンの有効性を決して漏洩しない） |
| `jti` クレーム | トークンごとに一意（将来の失効追跡） |

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| DB に生のリフレッシュトークンを保存する | DB 侵害ですべてのアクティブセッションが公開される |
| 失効時に物理削除を使用する | リプレイ攻撃を検出できない（トークンが存在したことを知るために `revoked = 1` が必要） |
| 長いアクセストークン TTL（時間/日） | 盗まれたトークンが長期アクセスを提供; リフレッシュトークンの目的を損なう |
| 無効なトークンでのログアウトに 401 を返す | 攻撃者がまだログインしているかどうかを調べられる |
| アクセストークンに `jti` なし | 将来の失効リストのための個別トークン追跡ができない |
| 単一トークン（アクセスのみ、リフレッシュなし） | ユーザーが 5 分ごとに再認証するか、危険に長い TTL を使用する必要がある |
| トークンハッシュに MD5 または SHA-1 を使用する | 弱いハッシュ; SHA-256 以上を使用する |
| リフレッシュトークンに有効期限なし | リフレッシュトークンが永遠に有効; 盗まれたトークンが無期限のアクセスを提供する |
