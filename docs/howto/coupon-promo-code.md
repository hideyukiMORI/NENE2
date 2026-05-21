# クーポン・プロモコード管理

admin RBAC・ユーザーごとの利用追跡・有効期限・上限制御を備えたクーポンシステムの実装ガイド。

## 概要

- admin ロールのみクーポン作成・無効化・利用履歴閲覧が可能
- 一般ユーザーは 1 クーポン 1 回のみ利用可能（`UNIQUE (coupon_id, user_id)`）
- `discount_pct`: 1〜100 の整数（バリデーション必須）
- `max_uses = 0` は上限なし
- `expires_at` は ISO 8601 文字列（NULL = 無期限）
- user_id は **X-User-Id ヘッダーのみ**から取得（ボディ注入不可）

## エンドポイント

| Method | Path | 説明 | 権限 |
|---|---|---|---|
| `POST` | `/coupons` | クーポン作成 | admin |
| `GET` | `/coupons/{code}` | クーポン情報取得 | 誰でも |
| `POST` | `/coupons/{code}/use` | クーポン利用（1ユーザー1回） | 認証済み |
| `GET` | `/coupons/{code}/uses` | 利用履歴一覧 | admin |
| `DELETE` | `/coupons/{code}` | クーポン無効化 | admin |

## データベース設計

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    created_at TEXT NOT NULL,
    CHECK (role IN ('user', 'admin'))
);

CREATE TABLE coupons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    discount_pct INTEGER NOT NULL CHECK (discount_pct >= 1 AND discount_pct <= 100),
    max_uses INTEGER NOT NULL DEFAULT 0,
    use_count INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    expires_at TEXT,
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE coupon_uses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coupon_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    used_at TEXT NOT NULL,
    UNIQUE (coupon_id, user_id),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE (coupon_id, user_id)` が同一ユーザーによる二重利用を DB レベルで防止する。

## admin チェックパターン

```php
private function requireUserId(ServerRequestInterface $request): ?int
{
    $val = $request->getHeaderLine('X-User-Id');
    return $val !== '' ? (int) $val : null;
}

private function isAdmin(ServerRequestInterface $request): bool
{
    return $request->getHeaderLine('X-User-Role') === 'admin';
}

// handleCreate / handleDeactivate / handleListUses の冒頭
$actorId = $this->requireUserId($request);
if ($actorId === null) {
    return $this->responseFactory->create(['error' => 'authentication required'], 401);
}
if (!$this->isAdmin($request)) {
    return $this->responseFactory->create(['error' => 'admin role required'], 403);
}
```

## クーポン利用チェック順序

```php
// 1. 認証チェック
if ($actorId === null) { return 401; }

// 2. クーポン存在確認
$coupon = $this->repository->findByCode($code);
if ($coupon === null) { return 404; }

// 3. is_active チェック
if (!(bool) $coupon['is_active']) { return 422 'not active'; }

// 4. 有効期限チェック
$now = date('c');
if ($coupon['expires_at'] !== null && $now > $coupon['expires_at']) { return 422 'expired'; }

// 5. max_uses チェック（0 は無制限）
if ($maxUses > 0 && $coupon['use_count'] >= $maxUses) { return 422 'limit reached'; }

// 6. ユーザー重複チェック（UNIQUE 制約のアプリ層確認）
$existing = $this->repository->findUse($coupon['id'], $actorId);
if ($existing !== null) { return 422 'already used'; }

// 7. 利用記録 + use_count インクリメント
$this->repository->recordUse($coupon['id'], $actorId, $now);
return 201;
```

## クーポン利用記録

```php
public function recordUse(int $couponId, int $userId, string $now): int
{
    $id = $this->executor->insert(
        'INSERT INTO coupon_uses (coupon_id, user_id, used_at) VALUES (?, ?, ?)',
        [$couponId, $userId, $now]
    );
    $this->executor->execute(
        'UPDATE coupons SET use_count = use_count + 1 WHERE id = ?',
        [$couponId]
    );
    return $id;
}
```

`use_count` のインクリメントは INSERT と同じ処理で実行する。
MySQL では並行アクセス時に `use_count = use_count + 1` がアトミックに機能する。

## discount_pct バリデーション

```php
$discountPct = isset($body['discount_pct']) && is_int($body['discount_pct']) ? $body['discount_pct'] : null;
if ($discountPct === null || $discountPct < 1 || $discountPct > 100) {
    return $this->responseFactory->create(['error' => 'discount_pct must be 1-100'], 422);
}
```

`CHECK (discount_pct >= 1 AND discount_pct <= 100)` は DB 側でも保証するが、
アプリ層でも先に拒否して適切な 422 を返す。

## レスポンス例

### POST /coupons（作成）
```json
{
  "id": 1,
  "code": "SUMMER20",
  "discount_pct": 20,
  "max_uses": 100,
  "use_count": 0,
  "is_active": true,
  "expires_at": "2026-08-31T23:59:59+00:00",
  "created_by": 1,
  "created_at": "2026-05-21T..."
}
```

### POST /coupons/{code}/use（利用）
```json
{
  "id": 42,
  "coupon_id": 1,
  "code": "SUMMER20",
  "discount_pct": 20,
  "user_id": 7,
  "used_at": "2026-05-21T..."
}
```

## user_id 注入防止

user_id は必ず `X-User-Id` ヘッダーから取得する。
リクエストボディの `user_id` フィールドは無視する。

```php
// NG: $userId = (int) $body['user_id'];  // 攻撃者に操作される
// OK:
$actorId = $this->requireUserId($request);  // X-User-Id ヘッダーのみ
```
