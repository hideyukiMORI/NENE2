# ハウツー: NENE2 でアクセストークン管理を構築する

このガイドでは、パーソナルアクセストークン（PAT）システムの構築方法を説明します — ユーザーが独自の API トークンをスコープ（`read`/`write`/`admin`）付きで発行、一覧表示、失効できます。トークンは平文では保存されません。SHA-256 ハッシュのみが保持されます。

**フィールドトライアル**: FT136  
**NENE2 バージョン**: ^1.5  
**カバートピック**: トークンハッシュ化、スコープ enum、所有権の強制、失効の冪等性、verify エンドポイント

---

## 構築するもの

- `POST /users/{id}/tokens` — トークンを発行する（所有者のみ、生トークンを一度だけ返す）
- `GET /users/{id}/tokens` — トークンを一覧表示する（所有者のみ、レスポンスに生トークンなし）
- `DELETE /users/{id}/tokens/{tokenId}` — トークンを失効させる（所有者のみ、すでに失効済みの場合は 409）
- `POST /tokens/verify` — 生トークンを検証する（有効/無効 + スコープを返す）

---

## データベーススキーマ

```sql
CREATE TABLE tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    scope      TEXT    NOT NULL DEFAULT 'read',
    label      TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    revoked_at TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id),
    CHECK (scope IN ('read', 'write', 'admin'))
);
```

- `token_hash` — 生トークンの SHA-256。生トークンは保存しない
- `revoked_at` — nullable タイムスタンプ。`NULL` = アクティブ、非 null = 失効済み
- `CHECK (scope IN (...))` — 多層防御としての DB レベルスコープ制約

---

## トークンスコープ enum

```php
enum TokenScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';
}
```

`TokenScope::tryFrom($value)` は未知のスコープに `null` を返します — 保存前に入力を検証するためにこれを使用してください。

---

## トークンの発行

```php
public function issueToken(int $userId, TokenScope $scope, string $label, string $now): string
{
    $raw  = bin2hex(random_bytes(32)); // 64 文字の hex 文字列
    $hash = hash('sha256', $raw);

    $this->executor->execute(
        'INSERT INTO tokens (user_id, token_hash, scope, label, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $hash, $scope->value, $label, $now],
    );

    return $raw; // 一度だけ返す。保存しない
}
```

生トークンは呼び出し元に一度だけ返されます。その後、データベースにはハッシュのみが残ります — 生トークンを回復する方法はありません。

---

## トークンの検証

```php
public function verifyToken(string $rawToken): ?array
{
    $hash = hash('sha256', $rawToken);
    $row  = $this->executor->fetchOne(
        'SELECT id, user_id, scope, revoked_at FROM tokens WHERE token_hash = ?',
        [$hash],
    );

    if ($row === null) {
        return null;
    }

    $arr = (array) $row;

    return [
        'valid'   => !isset($arr['revoked_at']),
        'user_id' => isset($arr['user_id']) ? (int) $arr['user_id'] : 0,
        'scope'   => isset($arr['scope']) && is_string($arr['scope']) ? $arr['scope'] : 'read',
    ];
}
```

**`!isset($arr['revoked_at'])` を使って `=== null` を使わない理由は？** `isset()` が true を返した後、PHPStan は型から `null` を除外します — `null` との比較は `identical.alwaysFalse` になります。null チェックには `isset()` だけを使用してください。

verify エンドポイントは未知または失効済みトークンに対して常に `{ "valid": false }` の 200 を返します — 404 は使いません。これによりトークン列挙を防止します。

---

## 所有権の強制

すべての変更エンドポイントは、認証済みアクターがリソースの所有者と一致することを確認します:

```php
$actorId = $this->resolveActorId($request); // X-User-Id ヘッダーから

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

失効の場合、トークン自体に対する 2 番目の所有権チェックがあります:

```php
$token = $this->repo->findTokenById($tokenId);

if ($token['user_id'] !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

これにより ATK-04 を防止します — Bob が自分のユーザーパスを使って Alice のトークン ID を失効させようとする攻撃。

---

## 失効 — すでに失効済みには 409

```php
public function revokeToken(int $tokenId, string $now): bool
{
    $count = $this->executor->execute(
        'UPDATE tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
        [$now, $tokenId],
    );

    return $count > 0;
}
```

`WHERE revoked_at IS NULL` ガードにより、トークンがすでに失効済みの場合は UPDATE が no-op になります。ハンドラーは `$count === 0` を 409 Conflict にマップします。

---

## トークン一覧 — 生トークンは含めない

一覧レスポンスには `id`、`scope`、`label`、`created_at`、`revoked`（bool）が含まれます。生トークンは最初の発行呼び出し後は返しません。

---

## PHPStan レベル 8 の落とし穴: isset + null 比較

```php
// 誤り — PHPStan が `notIdentical.alwaysTrue` を報告する
'revoked' => isset($arr['revoked_at']) && $arr['revoked_at'] !== null,

// 正しい — isset() はすでに非 null を意味する
'revoked' => isset($arr['revoked_at']),

// 誤り — PHPStan が `identical.alwaysFalse` を報告する
'valid' => !isset($arr['revoked_at']) || $arr['revoked_at'] === null,

// 正しい
'valid' => !isset($arr['revoked_at']),
```

---

## クラッカー攻撃テスト結果（FT136）

| 攻撃 | 期待値 | 結果 |
|--------|----------|--------|
| ATK-01: 別ユーザーのトークンを発行（IDOR） | 403 | Pass |
| ATK-02: 別ユーザーのトークンを一覧表示（IDOR） | 403 | Pass |
| ATK-03: 自分のパス経由で別ユーザーのトークンを失効 | 403 | Pass |
| ATK-04: 自分のパス経由で別ユーザーのトークンを失効 | 403 | Pass |
| ATK-05: 無効なスコープ（`superuser`） | 422 | Pass |
| ATK-06: 失効済みトークンを verify で使用 | valid=false | Pass |
| ATK-07: ランダムトークンをブルートフォース | valid=false | Pass |
| ATK-08: verify ボディへの SQL インジェクション | valid=false | Pass |
| ATK-09: 非数値の X-User-Id（`admin`） | 201 以外 | Pass |
| ATK-10: 負のユーザー ID | 404 | Pass |
| ATK-11: 10KB のスコープ文字列 | 422 | Pass |
| ATK-12: 空/空白のみのトークン | 422 | Pass |

12 件の攻撃テストすべてパス。

---

## よくある落とし穴

| 落とし穴 | 修正 |
|---------|-----|
| `isset($x) && $x !== null` | `isset($x)` だけを使う — PHPStan レベル 8 は冗長なチェックを拒否する |
| 生トークンを DB に保存する | `hash('sha256', $raw)` のみを保存する |
| 一覧レスポンスに生トークンを返す | 発行レスポンスでのみ生トークンを返す |
| 失効時にトークン所有権を確認しない | トークンを見つけた後に `token['user_id'] === userId` を確認する |
| verify で無効なトークンに 404 を返す | 常に `valid: false` で 200 を返す — 列挙を防止する |
