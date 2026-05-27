# ハウツー: 注文管理 API

> **FT リファレンス**: FT274 (`NENE2-FT/orderlog`) — 注文管理: SKU バリデート済みラインアイテム、total_cents の自動計算、ステータスライフサイクル（pending→confirmed→shipped→delivered→cancelled）、IDOR → 404、管理者オーバーライド、キャンセル競合検出、36 テスト PASS。
>
> FT215 (`NENE2-FT/orderlog` 前身）でも同じパターンを検証済みです。

このガイドでは NENE2 を使ったマルチアイテム注文管理 API の構築方法を解説します。

## 機能

- ラインアイテム（SKU + 数量 + 単価）付きの注文作成
- アイテムからの自動合計計算
- ステータスライフサイクル: `pending → confirmed → shipped → delivered → cancelled`
- ユーザースコープ IDOR 保護（存在を隠すために 403 ではなく 404 を返す）
- クロスユーザー操作のための管理者オーバーライド
- 競合検出付きのアトミックなキャンセル（`cancelled` または `delivered` はキャンセル不可）

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending',
    total_cents INTEGER NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
CREATE TABLE IF NOT EXISTS order_items (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id    INTEGER NOT NULL,
    sku         TEXT    NOT NULL,
    quantity    INTEGER NOT NULL,
    unit_cents  INTEGER NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_orders_user ON orders (user_id, id DESC);
```

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/orders` | アイテム付きで注文を作成する |
| `GET` | `/orders/{id}` | アイテム付きで注文を取得する（オーナーまたは管理者） |
| `POST` | `/orders/{id}/cancel` | 注文をキャンセルする（オーナーまたは管理者） |
| `GET` | `/users/{userId}/orders` | ユーザーの注文を一覧表示する（本人または管理者） |

## アイテムバリデーション

```php
/** SKU: 大文字英数字とハイフン、1〜32 文字 */
private const string SKU_PATTERN = '/\A[A-Z0-9\-]{1,32}\z/';

// アイテムごと:
// - sku: SKU_PATTERN にマッチしなければならない
// - quantity: 整数 1〜9999
// - unit_cents: 非負整数
// 注文ごとに最大 50 アイテム
```

## リポジトリパターン

```php
/**
 * @param list<array{sku: string, quantity: int, unit_cents: int}> $items
 * @return array<string, mixed>
 */
public function create(int $userId, string $note, array $items): array
{
    $totalCents = 0;
    foreach ($items as $item) {
        $totalCents += $item['quantity'] * $item['unit_cents'];
    }
    // orders を INSERT し、items を INSERT して、findById() を返す
}

/** @return 'not_found'|'not_cancellable'|'ok' */
public function cancel(int $id, int $userId, bool $isAdmin): string
{
    // 別のユーザーには 'not_found' を返す（IDOR 保護）
    // cancelled/delivered の注文には 'not_cancellable' を返す
}
```

## IDOR 保護

ユーザースコープのエンドポイントは、ユーザーが他のユーザーのリソースにアクセスした場合に `404`（`403` ではなく）を返します:

```php
// GET /orders/{id}
if (!$isAdmin && (int) $order['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Order not found.');
}

// GET /users/{userId}/orders
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## match 式によるキャンセル

```php
$result = $this->repo->cancel($id, $uid ?? 0, $isAdmin);

return match ($result) {
    'not_found'       => $this->problem(404, 'not-found', 'Order not found.'),
    'not_cancellable' => $this->problem(409, 'conflict', 'Order cannot be cancelled.'),
    default           => $this->json(['message' => 'Order cancelled.']),
};
```

## セキュリティパターン

- **管理者フェイルクローズド**: `hash_equals()` の前に `if ($this->adminKey === '') return false;`
- **`ctype_digit()`**: パスおよびヘッダー ID の ReDoS セーフ整数バリデーション
- **`is_int()`**: 厳密な型チェック — JSON として渡された float（例: `1.5`）を拒否
- **最大アイテムガード**: 大きすぎるペイロードを防ぐために 50 アイテムに制限

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| 価格を FLOAT として保存する | 合計の浮動小数点丸め誤差（整数セントを使う） |
| 自由形式の SKU 文字列を受け付ける | インジェクション面; 正規表現で許可リスト（例: `[A-Z0-9\-]{1,32}`） |
| 最大アイテム制限なし | 攻撃者が 10,000 アイテムの配列を送り、遅い INSERT ループを引き起こす |
| 合計をクライアント側で計算する | クライアントが任意の合計を送れる; 常に `quantity × unit_cents` から導出する |
| 別ユーザーの注文アクセスに 403 を返す | 注文の存在を明かす; 所有権を隠すために 404 を使う |
| delivered の注文のキャンセルを許可する | 履行済みの注文は不変であるべき; ステートマシンを使う |
| order_items の `ON DELETE CASCADE` を省略する | 注文を削除すると孤立したアイテムが残る |
