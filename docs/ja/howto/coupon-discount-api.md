# ハウツー: クーポン割引コード API

> **FT リファレンス**: FT302 (`NENE2-FT/couponlog`) — クーポン割引コード API: `hash_equals` による `X-Admin-Key` を使った管理者専用作成、CODE_PATTERN `[A-Z0-9]{4,32}` の大文字自動正規化、UNIQUE(coupon_id, user_id) による二重利用防止、期限切れ/使い切り/重複 → 409、26 テスト / 50 アサーション PASS。

このガイドでは、管理者が割引コードを作成し、ユーザーが使用制限と有効期限に対してそれを利用するクーポンシステムの構築方法を示します。

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS coupons (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT    NOT NULL UNIQUE,
    discount    INTEGER NOT NULL,          -- セント単位、例: 500 = $5.00
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

CREATE INDEX IF NOT EXISTS idx_coupons_code ON coupons (code);
```

`UNIQUE(coupon_id, user_id)` は同じユーザーが同じクーポンを 2 回利用することを防止します。`code` のインデックスでコード文字列によるルックアップを高速化します。

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|------|
| `POST` | `/coupons` | `X-Admin-Key` | クーポンを作成する（管理者のみ） |
| `GET` | `/coupons/{code}` | — | クーポン詳細を取得する |
| `POST` | `/coupons/{code}/redeem` | `X-User-Id` | クーポンを利用する |
| `GET` | `/coupons/{code}/redemptions` | `X-Admin-Key` | 利用履歴を一覧表示する（管理者のみ） |

## 管理者認証 — hash_equals

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` はキー比較に対するタイミングサイドチャネル攻撃を防止します。`adminKey` が空文字列（設定ミス）の場合、`isAdmin()` は false を返します — フェイルクローズ。

## クーポンコード形式 — CODE_PATTERN

```php
private const string CODE_PATTERN = '/\A[A-Z0-9]{4,32}\z/';
```

- 大文字英数字のみ
- 4〜32 文字
- `\A` / `\z` アンカー（部分一致ではなく文字列全体マッチ）

入力コードはバリデーション前に大文字に正規化されます:

```php
$code = strtoupper(trim((string) ($body['code'] ?? '')));
if ($code === '') {
    // 未指定の場合は自動生成
    $code = strtoupper(bin2hex(random_bytes(6)));
}
if (!preg_match(self::CODE_PATTERN, $code)) {
    return $this->problem(422, 'validation-failed', 'code must be 4–32 uppercase alphanumeric chars.');
}
```

`"summer50"` を送信したユーザーは `"SUMMER50"` と同じクーポンを取得します — システムが自動的に大文字に正規化します。`pathCode()` もパスパラメーターを大文字に正規化するため、`GET /coupons/summer50` と `GET /coupons/SUMMER50` は同じクーポンに解決されます。

## クーポン作成バリデーション

```php
$discount = $body['discount'] ?? null;
if (!is_int($discount) || $discount < 1 || $discount > 10000) {
    return $this->problem(422, 'validation-failed', 'discount must be integer 1–10000 (cents).');
}

$maxUses = $body['max_uses'] ?? 1;
if (!is_int($maxUses) || $maxUses < 1 || $maxUses > 100000) {
    return $this->problem(422, 'validation-failed', 'max_uses must be integer 1–100000.');
}

if (!preg_match(self::ISO_DATE_PATTERN, $expiresAt)) {
    return $this->problem(422, 'validation-failed', 'expires_at must be ISO 8601 datetime.');
}
```

- `discount`: 厳格な `is_int()` — `9.99` のような浮動小数点は拒否されます
- `max_uses`: 未指定の場合は `1` がデフォルト
- `expires_at`: `\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}` ISO 8601 プレフィックスに一致する必要があります

## 利用 — 4 つの失敗モード

```php
$result = $this->repo->redeem($code, $uid);

return match ($result) {
    'not_found'        => $this->problem(404, 'not-found', 'Coupon not found.'),
    'expired'          => $this->problem(409, 'conflict', 'Coupon has expired.'),
    'exhausted'        => $this->problem(409, 'conflict', 'Coupon usage limit reached.'),
    'already_redeemed' => $this->problem(409, 'conflict', 'You have already redeemed this coupon.'),
    default            => $this->json(['message' => 'Coupon redeemed successfully.']),
};
```

すべてのビジネスルール失敗は **409 Conflict** を返します（422 ではありません）。`match` 式は網羅的です — デフォルトブランチはリポジトリからの成功した `'redeemed'` 文字列の場合のみ発火します。

## ユーザー ID バリデーション

```php
private function uid(ServerRequestInterface $req): ?int
{
    $raw = $req->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
        return null;
    }
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

- `ctype_digit()` — 純粋な数字文字列のみ受け入れ（`-`、`+`、スペースは不可）
- `strlen > 18` — 64 ビット PHP での整数オーバーフローを防止（`PHP_INT_MAX` は 19 桁）
- `$id > 0` — ゼロ ID は無効

ヘッダーが欠落または不正な場合は `null` → 400 Bad Request を返します。

## UNIQUE(coupon_id, user_id) — 冪等な利用

DB 制約がストレージレベルで二重利用を防止します。アプリケーションも挿入前にリポジトリ経由でチェックし、DB 例外に依存せずに `'already_redeemed'` を返します。

異なる複数のユーザーは同じクーポンを（`max_uses` まで）利用できます。ブロックされるのは同じユーザーが同じクーポンを 2 回試みる場合だけです。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 管理キー比較に通常の `==` を使用 | タイミング攻撃でキーの長さや部分一致が判明する |
| 空の `adminKey` で管理者アクセスを許可 | 設定ミスの管理キーがオープンアクセスになる — フェイルクローズ |
| 大文字小文字を区別したコードルックアップ | `"summer50"` と `"SUMMER50"` が別のクーポンとして扱われる |
| `is_int()` なしの `discount` | 浮動小数点 `9.99` が受け入れられる。端数セントで台帳が壊れる |
| 期限切れ/使い切りに 422 を返す | これらはビジネス状態の競合であり、バリデーションエラーではない — 409 を使用する |
| UNIQUE(coupon_id, user_id) なし | 競合状態で同じユーザーが同時に 2 回利用できる |
| `max_uses` の上限なし | 攻撃者が `max_uses: 999999999` のクーポンを作成して事実上無制限の割引を受ける |
| ユーザー ID の `strlen > N` チェックをスキップ | 非常に大きな整数文字列が `(int)` キャストでサイレントにオーバーフローする |
| `code` カラムのインデックスなし | クーポンルックアップのたびにフルテーブルスキャン |
| 非管理者に利用履歴を返す | どのユーザー ID が利用したかが判明する — プライバシー漏洩 |
