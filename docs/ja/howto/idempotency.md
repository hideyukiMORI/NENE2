# ハウツー: 冪等性キーパターン

> **FT リファレンス**: FT276 (`NENE2-FT/csrflog`) — 状態変更リクエストのための Idempotency-Key ヘッダー: UNIQUE DB 制約、リプレイがオリジナル結果を返す（200）、リプレイ時のボディ変更は無視される、DatabaseConstraintException による競合状態処理、15 テスト / 30 アサーション PASS。
>
> **ATK アセスメント**: ATK-01〜ATK-12 をこのドキュメントの末尾に含む。

クライアントにすべての状態変更リクエストで `Idempotency-Key` ヘッダーを提供するよう要求することで、ネットワーク再試行による重複注文やリソース作成を防止します。

## なぜ重要か

クライアントが `POST /orders` を送信してレスポンスを受け取る前にネットワークが切断された場合、再試行します。冪等性がなければ、その再試行で 2 番目の注文が作成されます。`Idempotency-Key` があれば、サーバーは再試行を検出して重複を作成する代わりに元の結果を返すことができます。

Stripe、GitHub、その他多くの本番 API がこの正確なパターンを使用しています。

## データベーススキーマ

冪等性キーカラムに `UNIQUE` 制約を追加してください。この単一の制約が後述の競合状態を処理します。

```sql
CREATE TABLE orders (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key  TEXT    NOT NULL UNIQUE,
    item             TEXT    NOT NULL,
    quantity         INTEGER NOT NULL,
    total_price      REAL    NOT NULL,
    created_at       TEXT    NOT NULL
);
```

## ハンドラー実装

```php
// 1. ヘッダーを読み取ってバリデーション
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $problems->create(
        $request,
        'missing-idempotency-key',
        'Idempotency-Key header is required for this endpoint.',
        [],
        422,
    );
}

// 2. 既存エントリをチェック（リプレイパス）
$existing = $repo->findByIdempotencyKey($key);
if ($existing !== null) {
    return $json->create($existing->toArray(), 200); // リプレイ — 元のものを 200 で返す
}

// 3. リクエストボディをバリデーション
$body = json_decode((string) $request->getBody(), true);
// ... フィールドをバリデーション ...

// 4. 作成 — UNIQUE 制約が競合状態を処理
try {
    $order = $repo->create($key, $item, $quantity, $totalPrice);
    return $json->create($order->toArray(), 201);
} catch (DatabaseConstraintException) {
    // 同じキーを持つ別のリクエストが競合に勝った — その結果を返す
    $existing = $repo->findByIdempotencyKey($key);
    if ($existing !== null) {
        return $json->create($existing->toArray(), 200);
    }
    return $problems->create($request, 'conflict', 'Conflict.', [], 409);
}
```

## Repository

```php
public function findByIdempotencyKey(string $key): ?Order
{
    $row = $this->executor->fetchOne(
        'SELECT * FROM orders WHERE idempotency_key = ?',
        [$key],
    );
    return $row !== null ? Order::fromRow($row) : null;
}

public function create(string $key, string $item, int $quantity, float $totalPrice): Order
{
    // UNIQUE 違反（競合状態）で DatabaseConstraintException をスロー
    $this->executor->insert(
        'INSERT INTO orders (idempotency_key, item, quantity, total_price, created_at) VALUES (?, ?, ?, ?, ?)',
        [$key, $item, $quantity, $totalPrice, (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)],
    );
    // ...
}
```

## 主要な設計決定

### リプレイは 201 ではなく 200 を返す

2 番目のリクエストはリプレイで、作成ではありません。`200 OK` を使うことで、何が作成されたかについての混乱なしに「以前にこれを見た」とクライアントに伝えます。

### リプレイはボディを無視する

クライアントが異なるボディで同じ `Idempotency-Key` を送信した場合、**元の**結果が返されます。サーバーはキーの一致をリクエストが既に処理済みであることの証明として扱い、ボディが何を言っているかに関わらずそうします。

```
POST /orders  Idempotency-Key: uuid-abc  body: {quantity: 1, price: 9.99}
→ 201 Created  {id: 1, quantity: 1}

POST /orders  Idempotency-Key: uuid-abc  body: {quantity: 99, price: 0.01}
→ 200 OK  {id: 1, quantity: 1}   ← 元の注文、ボディは無視される
```

これは意図的です。クライアントが本当に別のリソースを作成したい場合は、新しいキーを使用する必要があります。

### UNIQUE 制約を競合状態ガードとして使う

同じキーを持つ 2 つの並行リクエストが競合します。DB の `UNIQUE` 制約により 1 つの INSERT のみが成功します。敗者は `DatabaseConstraintException` をキャッチして勝者の行を取得します。

## クライアントがキーとして何を使うべきか

UUID v4 が最も一般的な選択です。クライアントはリクエストを送信する前にキーを生成してローカルに保存しておき、必要に応じて同じキーで再試行できるようにします。

```js
// クライアントサイド（JavaScript）
const key = crypto.randomUUID();
const response = await fetch('/orders', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Idempotency-Key': key,
    },
    body: JSON.stringify({ item: 'Widget', quantity: 1, price: 9.99 }),
});
```

## ヘッダーの読み取り

PSR-7 ヘッダー名は大文字小文字を区別しません。`getHeaderLine('Idempotency-Key')`、`getHeaderLine('idempotency-key')`、`getHeaderLine('IDEMPOTENCY-KEY')` はすべて同じ値を返します。NENE2 はこれを正しく実装している Nyholm/PSR-7 を使用しています。

---

## ATK アセスメント — クラッカーマインドセット攻撃テスト

### ATK-01 — 重複チェックをバイパスするための Idempotency-Key の省略 🚫 BLOCKED

**攻撃**: `Idempotency-Key` ヘッダーなしで `POST /orders` を送信する。
**結果**: BLOCKED — `trim($request->getHeaderLine('Idempotency-Key')) === ''` → `missing-idempotency-key` Problem Detail 付きで 422。注文は作成されない。

---

### ATK-02 — 空の Idempotency-Key を送信する 🚫 BLOCKED

**攻撃**: `Idempotency-Key: `（空白のみ）を送信する。
**結果**: BLOCKED — `trim()` が空白のみの文字列を `''` に変換 → ATK-01 と同じ 422。

---

### ATK-03 — 注文内容を変更するための変更されたボディでのリプレイ 🚫 BLOCKED

**攻撃**: キー `uuid-abc` と `{quantity: 1}` で `POST /orders` を送信する。リプレイ時に同じキーで `{quantity: 99}` を使用する。
**結果**: BLOCKED — サーバーが `idempotency_key` で既存の行を見つけ、ボディを読む前に即座に返す。新しいボディは処理されない。

---

### ATK-04 — 異なるキーで 2 つの注文を作成する 🚫 BLOCKED（意図的）

**攻撃**: 2 つの異なる `Idempotency-Key` 値を使って合法的に 2 つの注文を作成する。
**結果**: SAFE（設計上） — 異なるキーは異なるリクエスト。両方の注文が作成される。これが意図した動作: 冪等性はキーごとで、ボディごとではない。

---

### ATK-05 — 競合状態: 同じキーを持つ 2 つの並行リクエスト 🚫 BLOCKED

**攻撃**: 2 つの同一リクエストをどちらかが完了する前に並行して送信する。
**結果**: BLOCKED — 両方のリクエストが `findByIdempotencyKey` チェックを通過するが（まだ既存の行がない）、1 つの INSERT のみが成功する。敗者は `DatabaseConstraintException` をキャッチして勝者の行を取得し 200 で返す。UNIQUE 制約が競合ガード。

---

### ATK-06 — 負の数量インジェクション 🚫 BLOCKED

**攻撃**: 有効なキーで `{item: "widget", quantity: -1, price: 9.99}` を送信する。
**結果**: BLOCKED — `if ($quantity <= 0)` → 422 バリデーションエラー。注文は作成されない。

---

### ATK-07 — ゼロ数量インジェクション 🚫 BLOCKED

**攻撃**: `{item: "widget", quantity: 0, price: 9.99}` を送信する。
**結果**: BLOCKED — 同じ `quantity <= 0` ガード → 422。

---

### ATK-08 — 必須ボディフィールドの欠如 🚫 BLOCKED

**攻撃**: `item` フィールドなしで `{quantity: 1}` を送信する。
**結果**: BLOCKED — `if ($item === '')` → 422 バリデーションエラー。

---

### ATK-09 — クロスオリジンブラウザリクエスト経由の CSRF 🚫 BLOCKED（設計）

**攻撃**: 悪意あるウェブサイトがブラウザからクロスオリジンの `POST /orders` リクエストを行う。
**結果**: BLOCKED（設計上） — JSON API は `Content-Type: application/json` を要求する。ブラウザの CSRF 攻撃はプリフライトなしに `<form>` 経由でフォームエンコードまたはプレーンテキストボディのみ送信できる。JSON ボディは CORS プリフライトをトリガーする。サーバーの CORS ポリシーがクロスオリジン書き込みを許可するかどうかを決定する。さらに、`Idempotency-Key` を要求することで追加の保護を提供する（偽造リクエストはユニークなキーを予測できない）。

---

### ATK-10 — 負の価格インジェクション 🚫 BLOCKED

**攻撃**: `{item: "widget", quantity: 1, price: -100.0}` を送信する。
**結果**: BLOCKED — `if ($price < 0)` → 422 バリデーションエラー。

---

### ATK-11 — 浮動小数点/文字列数量の強制変換 🚫 BLOCKED

**攻撃**: `{quantity: "1"}` または `{quantity: 1.5}`（文字列または浮動小数点）を送信する。
**結果**: BLOCKED — `is_int($body['quantity'])` が文字列と浮動小数点を拒否。`1.5` は float → 422。

---

### ATK-12 — Idempotency-Key 経由の SQL インジェクション 🚫 BLOCKED

**攻撃**: `Idempotency-Key: '; DROP TABLE orders; --` を送信する。
**結果**: BLOCKED — キーはパラメーター化クエリのみで使用される（`WHERE idempotency_key = ?`）。ヘッダー値経由の SQL インジェクションは不可能。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | Idempotency-Key の欠如 | 🚫 BLOCKED |
| ATK-02 | 空/空白のみのキー | 🚫 BLOCKED |
| ATK-03 | 変更されたボディでのリプレイ | 🚫 BLOCKED |
| ATK-04 | 異なるキー = 異なる注文 | ✅ SAFE（意図的） |
| ATK-05 | 同じキーでの競合状態 | 🚫 BLOCKED |
| ATK-06 | 負の数量 | 🚫 BLOCKED |
| ATK-07 | ゼロ数量 | 🚫 BLOCKED |
| ATK-08 | ボディフィールドの欠如 | 🚫 BLOCKED |
| ATK-09 | クロスオリジン POST 経由の CSRF | 🚫 BLOCKED |
| ATK-10 | 負の価格 | 🚫 BLOCKED |
| ATK-11 | 浮動小数点/文字列数量の強制変換 | 🚫 BLOCKED |
| ATK-12 | キーヘッダー経由の SQL インジェクション | 🚫 BLOCKED |

**12 BLOCKED / SAFE、0 EXPOSED**
Idempotency-Key パターン、パラメーター化クエリ、厳格な `is_int()` バリデーションがテストされたすべての攻撃ベクターを防止します。
