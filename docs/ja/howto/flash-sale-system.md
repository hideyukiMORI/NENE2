# NENE2 でフラッシュセールシステムを構築する方法

このガイドでは、ユーザーがセールウィンドウ内に割引価格で商品を購入できる、時間制限・数量制限付きのフラッシュセールシステムを構築する手順を解説します。

**フィールドトライアル**: FT140  
**NENE2 バージョン**: ^1.5  
**対象トピック**: タイムウィンドウバリデーション、COUNT(*) を使った在庫カウント、ユーザーごとに 1 回の購入のための UNIQUE 制約、ステータスのための `match` 式、クラッカーマインドセット攻撃テスト

---

## 構築するもの

- `POST /products` — 商品を作成する
- `POST /sales` — フラッシュセールを作成する（product_id、price、quantity、starts_at、ends_at）
- `GET /sales/{saleId}` — 残数とステータスを含むセール詳細を表示する
- `POST /sales/{saleId}/purchase` — アクティブウィンドウ内に購入する（ユーザーごとに 1 回）
- `GET /sales/{saleId}/purchases` — すべての購入者を一覧表示する

---

## データベーススキーマ

```sql
CREATE TABLE flash_sales (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    price      INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    starts_at  TEXT    NOT NULL,
    ends_at    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    CHECK (quantity > 0),
    CHECK (price >= 0),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE purchases (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id      INTEGER NOT NULL,
    user_id      INTEGER NOT NULL,
    purchased_at TEXT    NOT NULL,
    UNIQUE (sale_id, user_id),
    FOREIGN KEY (sale_id) REFERENCES flash_sales(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE (sale_id, user_id)` は、並行リクエスト下でもユーザーが同じセールを 2 回購入することを防ぎます。

---

## タイムウィンドウバリデーション

```php
$now = date('c');

if ($now < $sale['starts_at']) {
    return $this->responseFactory->create(['error' => 'sale has not started yet'], 422);
}

if ($now > $sale['ends_at']) {
    return $this->responseFactory->create(['error' => 'sale has ended'], 422);
}
```

`starts_at` / `ends_at` を ISO 8601 文字列として保存してください。フォーマットが辞書的に順序付けされているため、ISO 8601 では文字列比較が正しく機能します。

---

## COUNT(*) を使った在庫カウント

変更可能な `remaining` カラムを維持する代わりに、実際の購入数をカウントします:

```php
public function countPurchases(int $saleId): int
{
    $row = $this->executor->fetchOne(
        'SELECT COUNT(*) as cnt FROM purchases WHERE sale_id = ?',
        [$saleId],
    );

    return isset($row['cnt']) ? (int) $row['cnt'] : 0;
}
```

次にチェックします:

```php
$purchased = $this->repository->countPurchases($saleId);

if ($purchased >= $sale['quantity']) {
    return $this->responseFactory->create(['error' => 'sold out'], 422);
}
```

`remaining` は読み込み時に導出されます: `$sale['quantity'] - $purchased`。負の表示を避けるために `max(0, $remaining)` でクランプしてください。

---

## ユーザーごとに 1 回の購入 — UNIQUE 制約

`UNIQUE (sale_id, user_id)` が DB レベルで重複を防止します。`DatabaseConstraintException` が 409 にマップされます:

```php
public function purchase(int $saleId, int $userId, string $now): bool
{
    try {
        $this->executor->execute(
            'INSERT INTO purchases (sale_id, user_id, purchased_at) VALUES (?, ?, ?)',
            [$saleId, $userId, $now],
        );

        return true;
    } catch (DatabaseConstraintException) {
        return false;
    }
}
```

`purchase()` が `false` を返した場合、ハンドラーは 409 を返します。

---

## match 式によるセールステータス

```php
$status = match (true) {
    $now < $sale['starts_at'] => 'upcoming',
    $now > $sale['ends_at']   => 'ended',
    default                   => 'active',
};
```

3 つの状態: `upcoming`（開始前）、`active`（開催中）、`ended`（終了）。`match` 式は `default` がすべての他のケースをカバーするため網羅的です。

---

## クラッカー攻撃テスト結果（FT140）

| ID | 攻撃 | 期待結果 | 結果 |
|----|--------|----------|--------|
| ATK-01 | 商品名での SQL インジェクション | 201（そのまま保存） | Pass |
| ATK-02 | X-User-Id なしの購入 | 400 | Pass |
| ATK-03 | 非数値の X-User-Id | 201 でない | Pass |
| ATK-04 | URL の負の saleId | 201 でない | Pass |
| ATK-05 | セール開始前に購入 | 422 | Pass |
| ATK-06 | セール終了後に購入 | 422 | Pass |
| ATK-07 | 同じセールを二重購入 | 2 番目に 409 | Pass |
| ATK-08 | 在庫を尽かして購入 | 422 sold out | Pass |
| ATK-09 | quantity=0 のセール作成 | 422 | Pass |
| ATK-10 | 負の価格のセール作成 | 422 | Pass |
| ATK-11 | 存在しないユーザーとして購入 | 404 | Pass |
| ATK-12 | ends_at が starts_at より前 | 422 | Pass |

12 件すべての攻撃テストが Pass。

---

## よくある落とし穴

| 落とし穴 | 修正 |
|---------|-----|
| 変更可能な `remaining` カラムが並行性下でずれる | `purchases` テーブルからカウントし、読み込み時に `remaining` を導出する |
| API で quantity=0 を許可する | ハンドラーで `$quantity > 0` をバリデーション。スキーマに `CHECK (quantity > 0)` も |
| 負の価格が通り抜ける | `$price >= 0` をバリデーション。スキーマに `CHECK (price >= 0)` も |
| ユーザーが同じセールを 2 回購入 | `UNIQUE (sale_id, user_id)` + `DatabaseConstraintException` → 409 |
| 非 ISO 文字列での時刻比較 | ISO 8601 を使用（例: `date('c')`） — 辞書的順序が正しい |
| `ends_at` と `starts_at` が逆転 | INSERT 前に `$starts_at < $ends_at` をバリデーションする |
