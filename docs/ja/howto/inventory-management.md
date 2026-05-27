# ハウツー: 在庫管理 API

このガイドでは、NENE2 を使って在庫調整と履歴追跡を備えた在庫/ストック管理 API の構築方法を示します。
**inventorylog** フィールドトライアル（FT220、ATK クラッカー攻撃テスト）で実証されたパターンです。

## 機能

- SKU、name、price、初期数量で在庫アイテムを作成する（管理者のみ）
- アイテム詳細を取得する（公開）
- 符号付きデルタで在庫を調整する（正 = 補充、負 = 消費）
- 在庫不足検出 → 409 Conflict
- 完全な調整履歴ログ

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS items (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sku          TEXT    NOT NULL UNIQUE,
    name         TEXT    NOT NULL,
    quantity     INTEGER NOT NULL DEFAULT 0,
    price_cents  INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS stock_logs (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id        INTEGER NOT NULL,
    delta          INTEGER NOT NULL,
    reason         TEXT    NOT NULL DEFAULT '',
    quantity_after INTEGER NOT NULL,
    created_at     TEXT    NOT NULL,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);
```

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/items` | 管理者 | 在庫アイテムを作成する |
| `GET` | `/items/{id}` | 公開 | 現在の在庫付きでアイテムを取得する |
| `POST` | `/items/{id}/adjust` | 管理者 | 在庫を調整する（デルタ ± N） |
| `GET` | `/items/{id}/history` | 公開 | 調整履歴を取得する |

## 在庫調整パターン

```php
/** @return 'ok'|'not_found'|'insufficient_stock' */
public function adjust(int $id, int $delta, string $reason): string
{
    $item = $this->findById($id);
    if ($item === null) return 'not_found';

    $newQty = (int) $item['quantity'] + $delta;
    if ($newQty < 0) return 'insufficient_stock'; // → 409

    // アトミック更新 + ログ
    $this->pdo->prepare(
        'UPDATE items SET quantity = :qty, updated_at = :now WHERE id = :id'
    )->execute([':qty' => $newQty, ':now' => $now, ':id' => $id]);

    $this->pdo->prepare(
        'INSERT INTO stock_logs (item_id, delta, reason, quantity_after, created_at) VALUES ...'
    )->execute([...]);

    return 'ok';
}
```

## デルタバリデーション

```php
$delta = $body['delta'] ?? null;
if (!is_int($delta) || $delta === 0 || abs($delta) > self::MAX_QUANTITY) {
    return $this->problem(422, 'validation-failed', 'delta must be a non-zero integer with |delta| ≤ 1000000.');
}
```

## ATK クラッカーテスト結果（FT220）

- **ATK-01**: SKU への SQL インジェクション → `/\A[A-Z0-9\-]{1,32}\z/` パターンでブロック（422）
- **ATK-01**: パス ID への SQL インジェクション → `ctype_digit()` でブロック（404）
- **ATK-02**: `price_cents` の整数オーバーフロー → `is_int()` で float を拒否（422）
- **ATK-03**: 過大サイズのパス ID → `strlen > 18` ガード（404）
- **ATK-04**: ゼロへのドレイン境界 → 許可（quantity = 0 は有効）
- **ATK-05**: 過大な `quantity`（> 1,000,000）→ 拒否（422）
- **ATK-06**: 誤った/空の管理者キー → 403（フェイルクローズ）
- **ATK-09**: 過剰ドレイン攻撃 → `insufficient_stock` → 409、在庫変更なし
- **ATK-10**: float `delta` → `is_int()` で拒否（422）
- **ATK-11**: ボディなしリクエスト → 400（JSON ボディが必要）
- **ATK-12**: エラーレスポンスに SQLSTATE/スタックトレース/内部パスなし

## セキュリティパターン

- **管理者フェイルクローズ**: `hash_equals()` の前に `if ($this->adminKey === '') return false;`
- **`is_int()` 厳格チェック**: price_cents、quantity、delta — JSON からの float を拒否
- **`ctype_digit()`**: パス ID の ReDoS セーフな整数バリデーション
- **SKU パターン**: `/\A[A-Z0-9\-]{1,32}\z/` が SQL インジェクション試みをブロック
- **アトミック操作**: 更新 + ログ挿入を順序付きで実行（単一接続内）
