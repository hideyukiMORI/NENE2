# ハウツー: 在庫ストック管理

## 概要

このガイドでは NENE2 で在庫管理 API を構築する方法を解説します。SKU ベースのアイテム登録、入庫/出庫操作、負の在庫防止、トランザクション履歴の機能を含みます。

**参照実装**: `../NENE2-FT/inventorylog/`

---

## スキーマ設計

```sql
CREATE TABLE IF NOT EXISTS items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    sku        TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    stock      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS stock_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,   -- 'in' | 'out'
    quantity   INTEGER NOT NULL,
    note       TEXT,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);
```

---

## ルートテーブル

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/inventory/items` | アイテムを登録する（SKU + name） |
| `GET` | `/inventory/items` | すべてのアイテムを一覧表示する |
| `GET` | `/inventory/items/{id}` | ID でアイテムを取得する |
| `POST` | `/inventory/items/{id}/in` | 入庫（受け取り） |
| `POST` | `/inventory/items/{id}/out` | 出庫（出荷） |
| `GET` | `/inventory/items/{id}/history` | トランザクション履歴 |

---

## SKU バリデーション

インジェクションを防止し、正規の形式を確保するために SKU フォーマットを制限してください:

```php
if (!preg_match('/\A[A-Z0-9_-]{1,32}\z/', $sku)) {
    return $this->problem(422, 'validation-failed', 'sku must be uppercase alphanumeric (max 32).');
}
```

---

## 在庫操作

### 入庫

常に安全 — インクリメントするだけ:

```php
$this->pdo->prepare('UPDATE items SET stock = stock + :qty, updated_at = :now WHERE id = :id')
    ->execute([':qty' => $quantity, ':now' => $now, ':id' => $itemId]);
```

### 出庫（在庫不足ガード付き）

```php
if ((int) $item['stock'] < $quantity) {
    return 'insufficient_stock';   // → 409 Conflict
}
$this->pdo->prepare('UPDATE items SET stock = stock - :qty, updated_at = :now WHERE id = :id')
    ->execute([':qty' => $quantity, ':now' => $now, ':id' => $itemId]);
```

---

## 数量バリデーション

整数でない値と正でない値の数量を拒否してください:

```php
$qty = $body['quantity'] ?? null;
if (!is_int($qty) || $qty <= 0) {
    return [0, null, 'quantity must be a positive integer.'];
}
```

これは `"50"`（文字列）と `-1`（負）の両方をキャッチします。

---

## HTTP ステータスコード

| 状況 | ステータス |
|-----------|--------|
| アイテム作成 | 201 |
| 在庫追加 / 削減 | 200 |
| アイテム / 履歴が見つかった | 200 |
| フィールドが欠如または空 | 422 |
| 無効な SKU フォーマット | 422 |
| 整数でないまたは負の数量 | 422 |
| アイテムが見つからない | 404 |
| SKU 重複 | 409 |
| 在庫不足 | 409 |

---

## 注記

- **アトミック更新**: SQL で `stock = stock + :qty` と `stock = stock - :qty` を使用して、並行アクセス下でも残高を一貫させてください。
- **監査証跡**: すべての在庫変更はトレーサビリティのために `stock_history` 行を書き込みます。
- **ソフト制約**: アプリケーションはデクリメント前に在庫をチェックします。並行性下での厳格な正確性のためには、DB に `CHECK (stock >= 0)` カラム制約を追加するか、行ロック付きのトランザクションを使用してください。
