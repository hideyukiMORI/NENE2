# ハウツー: クーポン / 割引コード利用 API

このガイドでは、NENE2 を使って使用制限と有効期限を持つクーポン利用システムの構築方法を示します。
**couponlog** フィールドトライアル（FT218）で実証されたパターンです。

## 機能

- 割引額・使用制限・有効期限付きのクーポンコード作成（管理者のみ）
- ランダムコードのオプション自動生成（`bin2hex(random_bytes(6))`）
- 1 ユーザー 1 クーポン 1 回の利用（`UNIQUE(coupon_id, user_id)`）
- 使用制限の強制（`max_uses`）
- 現在の UTC 時刻に対する有効期限チェック
- 管理者専用の利用履歴一覧

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS coupons (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT    NOT NULL UNIQUE,
    discount    INTEGER NOT NULL,
    max_uses    INTEGER NOT NULL DEFAULT 1,
    used_count  INTEGER NOT NULL DEFAULT 0,
    expires_at  TEXT    NOT NULL,
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS redemptions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    coupon_id   INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    redeemed_at TEXT    NOT NULL,
    UNIQUE (coupon_id, user_id),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
);
```

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|------|
| `POST` | `/coupons` | 管理者 | クーポンを作成する |
| `GET` | `/coupons/{code}` | パブリック | クーポン情報を取得する |
| `POST` | `/coupons/{code}/redeem` | ユーザー | クーポンを利用する |
| `GET` | `/coupons/{code}/redemptions` | 管理者 | 利用履歴を一覧表示する |

## コードバリデーション

クーポンコードはインジェクション防止のために厳格なパターンを使用します:

```php
/** クーポンコード: 大文字英数字、4〜32 文字 */
private const string CODE_PATTERN = '/\A[A-Z0-9]{4,32}\z/';
```

パスパラメーターはバリデーション前に大文字に正規化されます:

```php
private function pathCode(ServerRequestInterface $req): ?string
{
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $code   = strtoupper(trim($params['code'] ?? ''));
    if (!preg_match(self::CODE_PATTERN, $code)) {
        return null; // → 404
    }
    return $code;
}
```

## 利用ロジック

```php
/** @return 'ok'|'not_found'|'expired'|'exhausted'|'already_redeemed' */
public function redeem(string $code, int $userId): string
{
    $coupon = $this->findByCode($code);
    if ($coupon === null) return 'not_found';

    // 有効期限チェック
    if ($coupon['expires_at'] < $this->now()) return 'expired';

    // 使用制限チェック
    if ((int) $coupon['used_count'] >= (int) $coupon['max_uses']) return 'exhausted';

    // ユーザーごとの制限チェック
    $stmt = $this->pdo->prepare(
        'SELECT id FROM redemptions WHERE coupon_id = :cid AND user_id = :uid'
    );
    if ($stmt->fetch() !== false) return 'already_redeemed';

    // 記録 + カウンターインクリメント
    $this->pdo->prepare('INSERT INTO redemptions ...')->execute([...]);
    $this->pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = :id')
        ->execute([':id' => $coupon['id']]);

    return 'ok';
}
```

ルートハンドラーは `match` 式を使って分岐をクリーンにします:

```php
return match ($result) {
    'not_found'        => $this->problem(404, 'not-found', 'Coupon not found.'),
    'expired'          => $this->problem(409, 'conflict', 'Coupon has expired.'),
    'exhausted'        => $this->problem(409, 'conflict', 'Coupon usage limit reached.'),
    'already_redeemed' => $this->problem(409, 'conflict', 'You have already redeemed this coupon.'),
    default            => $this->json(['message' => 'Coupon redeemed successfully.']),
};
```

## 自動生成コード

リクエストボディに `code` が提供されない場合、自動生成されます:

```php
$code = strtoupper(trim((string) ($body['code'] ?? '')));
if ($code === '') {
    $code = strtoupper(bin2hex(random_bytes(6))); // 12 文字の大文字 16 進数
}
```

## セキュリティパターン

- **管理者フェイルクローズ**: `hash_equals()` の前に `if ($this->adminKey === '') return false;`
- **コードパターン**: コード用の `ctype_digit()` 相当 — 正規表現 `/\A[A-Z0-9]{4,32}\z/`
- **`is_int()`**: `discount` と `max_uses` の厳格な型チェック — 浮動小数点を拒否
- **ISO 8601 有効期限**: 正規表現バリデーション + 辞書順比較（UTC 文字列）
- **アトミックインクリメント**: `UPDATE SET used_count = used_count + 1` で競合状態を防止
- **UNIQUE 制約**: 重複防止のためのデータベースレベルのセーフティネット
