# ハウツー: セッション/トークン管理 API（ATK-01〜12）

このガイドでは、ATK-01〜12 のすべてのクラッカー攻撃ベクターをカバーするセキュアなセッショントークン API を実演します。

## パターン概要

- `POST /sessions` — ユーザーに新しい不透明なトークンを発行する（`X-User-Id` 必須）。
- `GET /sessions/{token}` — トークンを検証する（失効または期限切れの場合は 404）。
- `DELETE /sessions/{token}` — トークンを失効させる（オーナーまたは管理者）。
- `GET /users/{userId}/sessions` — アクティブなセッションを一覧表示する（オーナーまたは管理者）。

トークンは `bin2hex(random_bytes(32))` — 64 の小文字 16 進文字です。

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS sessions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    label      TEXT    NOT NULL DEFAULT '',
    revoked    INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions (token);
CREATE INDEX IF NOT EXISTS idx_sessions_user  ON sessions (user_id, revoked);
```

## トークン生成

```php
$token = bin2hex(random_bytes(32));  // 64 の小文字 16 進文字
```

`random_bytes()` は CSPRNG を使用します; トークンは推測不可能で非逐次的です。

## トークンフォーマットバリデーション

DB 検索の前に、厳密な正規表現でトークンフォーマットを検証してください:

```php
private const string TOKEN_PATTERN = '/\A[0-9a-f]{64}\z/';

private function pathToken(ServerRequestInterface $req): ?string
{
    $token = $params['token'] ?? '';
    if (!preg_match(self::TOKEN_PATTERN, $token)) {
        return null;  // 404 — DB に到達しない
    }
    return $token;
}
```

これにより、SQL インジェクションペイロード、過大な入力、大文字 16 進文字、非 16 進文字列が DB クエリの前に拒否されます。

## ATK-01: トークンパスへの SQL インジェクション

トークンフォーマット正規表現が `' OR '1'='1` を即座に拒否します。通過しても、DB クエリはパラメーター化ステートメントを使用します:

```php
$stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE token = :token');
$stmt->execute([':token' => $token]);
```

## ATK-02〜04: トークンフォーマット攻撃

正規表現 `/\A[0-9a-f]{64}\z/` によってすべて拒否されます:
- 空文字列（長さ 0 ≠ 64）
- 過大な文字列（256 文字 ≠ 64）
- 非 16 進文字（`g`、`A`〜`F` の大文字、特殊文字）
- 誤った長さ（63 文字または 65 文字）

## ATK-05: X-User-Id の整数オーバーフロー

```php
if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
    return null;
}
```

19 桁の整数は 18 文字の制限を超えるため、`(int)` キャストの前に拒否されます。

## ATK-06: 負またはゼロのユーザー ID

```php
$id = (int) $raw;
return $id > 0 ? $id : null;
```

`0` と負の値は `null` を返し、400 をトリガーします。

## ATK-07: 管理者キーのフェイルクローズ

空の `adminKey` は管理者権限を与えません:

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` はキーを比較する際のタイミング攻撃を防ぎます。

## ATK-08: X-User-Id: 0 による認証バイパス

`uid()` は ID=0 に対して `null` を返します → 400（200 ではない）。

## ATK-09: 一覧パスの非数値ユーザー ID

`ctype_digit()` が `abc`、`1.5`、`-1` を拒否します:

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## ATK-10: 浮動小数点 TTL

`is_int()` は PHP の厳密型チェック — `60.5` は `false` を返します:

```php
if (!is_int($ttlRaw) || $ttlRaw < 1 || $ttlRaw > self::MAX_TTL) {
    return $this->problem(422, ...);
}
```

## ATK-11: 二重失効は 404 を返す

リポジトリは更新前に `revoked === 1` を確認します:

```php
if ($session === null || (int) $session['revoked'] === 1) {
    return false;
}
```

すでに失効したセッションは DELETE を再送信して「失効解除」できません。

## ATK-12: ブルートフォースのトークンフォーマット拒否

64 の小文字 16 進文字と正確に一致しないトークンはすべて、データベースに触れる前に 404 で拒否されます。ブルートフォース試行は DB ではなく正規表現の壁に当たります。

## IDOR: オーナーと管理者

- 非オーナーが別のユーザーのセッションを失効させたり一覧表示しようとすると 404（403 ではない）を受け取ります。
- 管理者は `X-Admin-Key` ヘッダーを使用します; キーが未設定の場合はフェイルクローズします。

## 参照先

- FT208 ソース: `../NENE2-FT/sessionlog/`
- 関連: `docs/howto/rate-limiting.md`（FT200、ATK）
- 関連: `docs/howto/coupon-redemption.md`（FT204、VULN + ATK）
