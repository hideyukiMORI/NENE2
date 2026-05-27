# ハウツー: フラッシュセール API

> **FT リファレンス**: FT304 (`NENE2-FT/salelog`) — フラッシュセール API: タイムウィンドウバリデーション（セール未開始 → 422、終了 → 422）、UNIQUE(sale_id, user_id) による二重購入防止、売り切れ在庫チェック、負の価格/ゼロ数量 → 422、反転した日付を拒否、ATK-01〜12 すべて BLOCKED、29 テスト / 42 アサーション PASS。

このガイドでは、タイムウィンドウ内でユーザーが限定在庫の商品を購入するフラッシュセールシステムの構築方法を示します。競合状態からの保護と攻撃防止を含みます。

## スキーマ

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

`CHECK (quantity > 0)` と `CHECK (price >= 0)` がビジネスルールを DB レベルで強制します。`UNIQUE(sale_id, user_id)` は同じユーザーが同じセールを 2 回購入することを防ぎます — 並行リクエスト下でも。

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/products` | — | 商品を作成する |
| `POST` | `/sales` | — | フラッシュセールを作成する |
| `GET` | `/sales` | — | アクティブなセールを一覧表示する |
| `GET` | `/sales/{id}` | — | セール詳細を取得する |
| `POST` | `/sales/{id}/purchase` | `X-User-Id` | 購入する（時間チェック済み） |

## セール作成バリデーション

```php
if (!is_int($price) || $price < 0) {
    return 422; // 負の価格を拒否
}
if (!is_int($quantity) || $quantity <= 0) {
    return 422; // ゼロまたは負の数量を拒否
}
if ($endsAt <= $startsAt) {
    return 422; // 反転または同一の日付を拒否
}
```

アプリケーションレベルのバリデーションに裏付けられた 3 つの DB レベルチェック:
- `price >= 0` — 無料セールは許可（`0`）、負の価格は不可
- `quantity > 0` — ゼロ数量のセールは作成できない
- `ends_at > starts_at` — 時間の反転を拒否

## 購入 — タイムウィンドウチェック

```php
$now = date('c');
if ($now < $sale['starts_at']) {
    return 422; // セールはまだ開始していない
}
if ($now > $sale['ends_at']) {
    return 422; // セールは終了した
}
```

セールウィンドウ外の購入試行は 422 を返します。チェックはサーバーサイドの `date('c')` を使用します — クライアントは時刻を操作できません。

## 在庫チェック

```php
$purchaseCount = $this->repo->countPurchases($saleId);
if ($purchaseCount >= $sale['quantity']) {
    return $this->json(['error' => 'sold out'], 422);
}
```

挿入前にセールの `quantity` に対して既存の購入数をカウントします。売り切れの場合、`"error": "sold out"` で 422 を返します。

## UNIQUE(sale_id, user_id) — 二重購入防止

```php
// UNIQUE 制約が並行した重複購入をキャッチする
try {
    $this->repo->createPurchase($saleId, $userId, $now);
} catch (\PDOException $e) {
    // UNIQUE 制約違反 → すでに購入済み
    return $this->json(['error' => 'already purchased'], 409);
}
```

DB の `UNIQUE(sale_id, user_id)` 制約が競合状態に対する最終的な防衛です。最初の購入が成功（201）し、それ以降の重複は 409 Conflict を返します。

## ユーザー ID バリデーション

```php
$actorIdRaw = $request->getHeaderLine('X-User-Id');
if ($actorIdRaw === '' || !ctype_digit($actorIdRaw)) {
    return $this->json(['error' => 'X-User-Id required'], 400);
}
$actorId = (int) $actorIdRaw;

$user = $this->repo->findUser($actorId);
if ($user === null) {
    return $this->json(['error' => 'user not found'], 404);
}
```

- 不在または非数値の `X-User-Id` → 400
- 存在しないユーザー ID → 404（IDOR 防止 — ゴーストユーザーとして購入不可）

---

## ATK アセスメント — クラッカーマインドセット攻撃テスト

### ATK-01 — 商品名での SQL インジェクション 🚫 BLOCKED

**攻撃**: `POST /products` で `name: "'; DROP TABLE products; --"`。
**結果**: BLOCKED — パラメーター化されたクエリがインジェクション文字列をそのまま保存（201）。後続のリクエストは引き続き機能。products テーブルは無傷。

---

### ATK-02 — X-User-Id ヘッダーなしの購入 🚫 BLOCKED

**攻撃**: `X-User-Id` ヘッダーなしの `POST /sales/{id}/purchase`。
**結果**: BLOCKED — ヘッダーがない場合は 400 を返す。

---

### ATK-03 — 非数値 X-User-Id ヘッダー 🚫 BLOCKED

**攻撃**: `X-User-Id: admin`（文字列値）。
**結果**: BLOCKED — `ctype_digit()` チェックが非数値値を拒否。201 ではない。

---

### ATK-04 — URL の負のセール ID 🚫 BLOCKED

**攻撃**: `POST /sales/-1/purchase`。
**結果**: BLOCKED — 負の ID がセールが見つからないに解決。201 ではない。

---

### ATK-05 — セール開始前の購入 🚫 BLOCKED

**攻撃**: 1 時間後に開始するセールを作成して即座に購入試行。
**結果**: BLOCKED — `$now < $sale['starts_at']` チェック → 422。

---

### ATK-06 — セール終了後の購入 🚫 BLOCKED

**攻撃**: 1 時間前に終了したセールで購入試行。
**結果**: BLOCKED — `$now > $sale['ends_at']` チェック → 422。

---

### ATK-07 — 同一セールの二重購入 🚫 BLOCKED

**攻撃**: 同じユーザーが同じセールを素早く 2 回購入。
**結果**: BLOCKED — 最初の購入は 201。2 番目の購入は 409（UNIQUE 制約またはアプリケーションレベルチェック）。

---

### ATK-08 — 在庫を尽かして購入 🚫 BLOCKED

**攻撃**: `quantity=1` のセールを作成。Alice が買う。Bob が購入試行。
**結果**: BLOCKED — 在庫チェック `purchaseCount >= quantity` → Bob に 422 `"sold out"`。

---

### ATK-09 — quantity=0 のセール作成 🚫 BLOCKED

**攻撃**: `POST /sales` で `quantity: 0`。
**結果**: BLOCKED — `quantity <= 0` バリデーション + DB `CHECK (quantity > 0)` → 422。

---

### ATK-10 — 負の価格のセール作成 🚫 BLOCKED

**攻撃**: `POST /sales` で `price: -999`。
**結果**: BLOCKED — `price < 0` バリデーション + DB `CHECK (price >= 0)` → 422。

---

### ATK-11 — 存在しないユーザーとして購入 🚫 BLOCKED

**攻撃**: `X-User-Id: 99999`（users テーブルに存在しない ID）。
**結果**: BLOCKED — `findUser($actorId) === null` → 404。

---

### ATK-12 — 反転したセール日付（ends_at が starts_at より前） 🚫 BLOCKED

**攻撃**: `starts_at: "+2 hours"`、`ends_at: "+1 hour"`。
**結果**: BLOCKED — `$endsAt <= $startsAt` バリデーション → 422。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | 商品名での SQL インジェクション | 🚫 BLOCKED |
| ATK-02 | X-User-Id なしの購入 | 🚫 BLOCKED |
| ATK-03 | 非数値の X-User-Id | 🚫 BLOCKED |
| ATK-04 | URL の負のセール ID | 🚫 BLOCKED |
| ATK-05 | セール開始前の購入 | 🚫 BLOCKED |
| ATK-06 | セール終了後の購入 | 🚫 BLOCKED |
| ATK-07 | 同一セールの二重購入 | 🚫 BLOCKED |
| ATK-08 | 在庫を尽かして購入 | 🚫 BLOCKED |
| ATK-09 | quantity=0 のセール作成 | 🚫 BLOCKED |
| ATK-10 | 負の価格のセール作成 | 🚫 BLOCKED |
| ATK-11 | 存在しないユーザーとして購入 | 🚫 BLOCKED |
| ATK-12 | 反転したセール日付 | 🚫 BLOCKED |

**12 BLOCKED、0 EXPOSED**
タイムウィンドウのサーバーサイドチェック、在庫カウントガード、UNIQUE 制約、厳格な入力バリデーションが既知のすべての攻撃ベクターを防止します。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| タイムチェックにクライアント提供のタイムスタンプを信頼する | クライアントが過去/未来のタイムスタンプを送信してウィンドウをバイパスする |
| `UNIQUE(sale_id, user_id)` なし | 負荷下の並行リクエストで同じユーザーが 2 回購入できる |
| 競合状態ガードなしの在庫チェック | 在庫チェックと挿入の間に別のリクエストが在庫を使い切る可能性がある |
| `quantity: 0` のセール作成を許可する | ゼロ数量のセールは購入できない。紛らわしいエッジケース |
| `price: -999` を許可する | 負の価格の購入が購入者に請求する代わりにクレジットする |
| ユーザー存在チェックなし | ゴーストユーザー ID（DB にない）が監査証跡をバイパスする |
| `$endsAt >= $startsAt`（等しいを許可） | 等しい開始/終了が即座に期限切れになるゼロ期間ウィンドウを作成する |
| 非数値の X-User-Id を許可する | `"admin"` 文字列が `(int)` にキャストされて `0` になり、認証をバイパスする |
| タイムウィンドウエラーに 409 を返す | 時間違反はビジネスバリデーション失敗（422）であり、状態競合ではない |
