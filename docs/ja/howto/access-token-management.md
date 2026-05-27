# NENE2 でアクセストークン管理を構築する方法

このガイドでは、パーソナルアクセストークン（PAT）システムの構築方法を説明します — ユーザーが自分の API トークンを発行・一覧表示・失効でき、それぞれにスコープ（`read`/`write`/`admin`）が付きます。トークンは平文で保存されず、SHA-256 ハッシュのみが保存されます。

**フィールドトライアル**: FT136  
**NENE2 バージョン**: ^1.5  
**対象トピック**: トークンハッシュ化、スコープ列挙型、所有権の強制、失効の冪等性、検証エンドポイント

---

## 構築するもの

- `POST /users/{id}/tokens` — トークンを発行する（所有者のみ、生トークンを一度だけ返す）
- `GET /users/{id}/tokens` — トークンを一覧表示する（所有者のみ、レスポンスに生トークンなし）
- `DELETE /users/{id}/tokens/{tokenId}` — トークンを失効させる（所有者のみ、すでに失効済みの場合は 409）
- `POST /tokens/verify` — 生トークンを検証する（有効/無効とスコープを返す）

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

- `token_hash` — 生トークンの SHA-256；生トークンは保存しない
- `revoked_at` — NULL 可タイムスタンプ；`NULL` = アクティブ、非 null = 失効済み
- `CHECK (scope IN (...))` — 多層防御としての DB レベルのスコープ制約

---

## トークンスコープ列挙型

```php
enum TokenScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';
}
```

`TokenScope::tryFrom($value)` は未知のスコープに対して `null` を返します — 保存前の入力バリデーションにこれを使用してください。

---

## トークンの発行

```php
public function issueToken(int $userId, TokenScope $scope, string $label, string $now): string
{
    $raw  = bin2hex(random_bytes(32)); // 64 文字の 16 進数文字列
    $hash = hash('sha256', $raw);

    $this->executor->execute(
        'INSERT INTO tokens (user_id, token_hash, scope, label, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $hash, $scope->value, $label, $now],
    );

    return $raw; // 一度だけ返す、保存はしない
}
```

生トークンは呼び出し元に一度だけ返されます。その後はハッシュのみがデータベースに存在し、生トークンを復元する方法はありません。

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

**なぜ `!isset($arr['revoked_at'])` であって `=== null` ではないのか？** `isset()` が true を返した後、PHPStan は型から `null` を除去します — `null` との比較は `identical.alwaysFalse` として報告されます。null チェックには `isset()` のみを使用してください。

検証エンドポイントは未知または失効済みトークンに対して常に 200 と `{ "valid": false }` を返します — 404 は返しません。これによりトークンの列挙を防止します。

---

## 所有権の強制

すべての変更エンドポイントで、認証済みアクターがリソース所有者と一致することを確認します:

```php
$actorId = $this->resolveActorId($request); // X-User-Id ヘッダーから

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

失効の場合、トークン自体に対する 2 回目の所有権チェックがあります:

```php
$token = $this->repo->findTokenById($tokenId);

if ($token['user_id'] !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

これにより ATK-04 を防止します — Bob が自分のユーザーパスを使って Alice のトークン ID を失効させようとするケースです。

---

## 失効 — すでに失効済みの場合は 409

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

`WHERE revoked_at IS NULL` ガードにより、トークンがすでに失効済みの場合 UPDATE は何もしません。ハンドラーは `$count === 0` を 409 Conflict にマップします。

---

## トークン一覧 — 生トークンは含めない

一覧レスポンスには `id`、`scope`、`label`、`created_at`、`revoked`（真偽値）が含まれます。生トークンは初回発行レスポンス以降は返されません。

---

## PHPStan レベル 8 の落とし穴: isset と null 比較

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
| ATK-01: 別ユーザーへのトークン発行（IDOR） | 403 | 合格 |
| ATK-02: 別ユーザーのトークン一覧（IDOR） | 403 | 合格 |
| ATK-03: 自分のパス経由で別ユーザーのトークンを失効 | 403 | 合格 |
| ATK-04: 自分のパスで別ユーザーのトークン ID を失効 | 403 | 合格 |
| ATK-05: 無効なスコープ（`superuser`） | 422 | 合格 |
| ATK-06: 失効済みトークンで検証 | valid=false | 合格 |
| ATK-07: ランダムトークンのブルートフォース | valid=false | 合格 |
| ATK-08: 検証ボディへの SQL インジェクション | valid=false | 合格 |
| ATK-09: 数値以外の X-User-Id（`admin`） | 201 以外 | 合格 |
| ATK-10: 負のユーザー ID | 404 | 合格 |
| ATK-11: 10KB のスコープ文字列 | 422 | 合格 |
| ATK-12: 空または空白のみのトークン | 422 | 合格 |

12 件の攻撃テストすべてが合格。

---

## よくある落とし穴

| 落とし穴 | 対処法 |
|---------|-----|
| `isset($x) && $x !== null` | `isset($x)` のみを使用 — PHPStan レベル 8 は冗長なチェックを拒否する |
| 生トークンを DB に保存する | `hash('sha256', $raw)` のみを保存する |
| 一覧レスポンスで生トークンを返す | 生トークンは発行レスポンスでのみ返す |
| 失効時にトークンの所有権を確認しない | トークン取得後に `token['user_id'] === userId` を確認する |
| 検証で無効トークンに 404 を返す | 常に 200 と `valid: false` を返す — 列挙を防止する |
