# パスワードリセットフロー

安全なトークンベースのパスワードリセットを実装します: リクエスト → 検証 → 完了。

## 概要

パスワードリセットフローには 3 つのステップがあります:
1. ユーザーがリセットをリクエストする — 時間制限付きトークンが生成されて送信される（例: メールで）。
2. ユーザーがリセットフォームを表示する前にトークンがまだ有効かを確認する。
3. ユーザーが新しいパスワードを送信する — トークンが消費されてパスワードが更新される。

## データベーススキーマ

```sql
CREATE TABLE password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    used_at    TEXT,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`token_hash` は生のトークンの SHA-256 ハッシュを保存します。生のトークンはデータベースに保存されません。

## トークン生成と保存

`random_bytes` で生のトークンを生成し、SHA-256 ハッシュのみを保存します:

```php
$rawToken  = bin2hex(random_bytes(32)); // 256 ビットエントロピー、64 hex 文字
$tokenHash = hash('sha256', $rawToken);

$this->repo->createReset($userId, $tokenHash, $expiresAt, $now);

// $rawToken をユーザーに返す（メールまたは API レスポンス経由）
```

検証時は、受信したトークンを同じ方法でハッシュ化します:

```php
$tokenHash = hash('sha256', $rawToken);
$reset     = $this->repo->findByTokenHashOrNull($tokenHash);
```

ハッシュを保存することで、DB 漏洩によって使用可能なリセットトークンが公開されません — 攻撃者は 256 ビットのランダム値の SHA-256 を逆算する必要がありますが、これは計算上不可能です。

## ユーザー列挙防止

`POST /password-reset` は不明なメールアドレスでも常に 202 を返す必要があります:

```php
$user = $this->repo->findUserByEmail($email);

// 常に 202 — メールが登録済みかどうかを明かさない
if ($user === null) {
    return $this->json->create(['status' => 'pending'], 202);
}

// ... 実際のユーザーのトークンを生成する
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

不明なメールに 404 を返すと、攻撃者がメールアドレスをプローブして登録済みアカウントを列挙できるようになります。

## 1 回限りの使用

リセット完了時に `used_at` を設定します。`used_at IS NOT NULL` のトークンは拒否します:

```php
if ($reset->isUsed()) {
    return $this->problems->create($request, 'conflict', 'Reset token has already been used.', 409, '');
}

$this->repo->markUsed($tokenHash, $now);
```

```php
public function isUsed(): bool
{
    return $this->usedAt !== null;
}
```

## 期限切れ

GET（ステータス確認）と POST（完了）の両方で期限切れを強制します。`isUsed()` チェックより前に期限切れを常にチェックします:

```php
if ($reset->isExpired($now)) {
    return 410; // Gone — 「見つからない」（404）と「使用済み」（409）と区別される
}
if ($reset->isUsed()) {
    return 409;
}
```

410（Gone）は「期限切れ」を「使用済み」（409）から区別し、ユーザーに実行可能な情報を提供します。

## 古いトークンの無効化

ユーザーが新しいリセットをリクエストした場合、そのユーザーの以前の未使用トークンをすべて無効化します:

```php
$this->executor->execute(
    "UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL",
    [$now, $userId],
);
```

これなしでは、リセットメールを紛失して新しいものをリクエストしたユーザーが同時に 2 つの有効なトークンを持つことになります — どちらもパスワードのリセットに使用できます。

## レスポンスのサニタイズ

`GET /password-reset/{token}` はレスポンスで `user_id` や `token_hash` を公開してはなりません:

```php
public function toArray(): array
{
    return [
        'id'         => $this->id,
        'expires_at' => $this->expiresAt,
        'created_at' => $this->createdAt,
    ];
}
```

`user_id` を公開するとリセットトークンをユーザーアカウント ID にリンクしてしまいます。トークン自体が認証情報であるため不要です。

## セキュリティプロパティ

| プロパティ | 実装 |
|---|---|
| トークンエントロピー | `bin2hex(random_bytes(32))` — 256 ビット |
| トークン保存 | SHA-256 ハッシュのみ — 生のトークンは DB に保存しない |
| ユーザー列挙 | `POST /password-reset` から常に 202 |
| 期限切れ | 1 時間; GET と POST でチェック |
| 1 回限りの使用 | 完了時に `used_at` を設定; 再使用に 409 |
| 古いトークンの無効化 | 新しいリクエスト時に以前の未使用トークンを使用済みに設定 |
| レスポンス漏洩 | `user_id` と `token_hash` はすべてのレスポンスから除外 |
| パスワードハッシュ | Argon2id |

## ルートサマリー

| メソッド | パス | 説明 |
|---|---|---|
| `POST` | `/password-reset` | リセットをリクエストする（常に 202） |
| `GET` | `/password-reset/{token}` | トークンの有効性を確認する |
| `POST` | `/password-reset/{token}` | 新しいパスワードでリセットを完了する |
