# ハウツー: 商品価格履歴 API

> **FT リファレンス**: FT67 (`NENE2-FT/pricelog`) — 商品価格履歴 API
> **ATK**: FT228 — クラッカー視点の攻撃テスト（ATK-01〜ATK-12）

各商品が価格ティア（有効期間）のタイムラインを管理する価格履歴 API を示します。現在の価格と任意の時点での価格を照会できます。ATK セクションでは 12 の攻撃ベクターと合否判定を記録しています。

---

## ルート

| メソッド | パス | 説明 |
|--------|-----------------------------------|----------------------------------------|
| `POST` | `/products` | 商品を作成する |
| `GET` | `/products` | すべての商品を一覧表示する |
| `GET` | `/products/{id}` | 単一商品を取得する |
| `POST` | `/products/{id}/prices` | 新しい価格を設定する（新しいティアを開く） |
| `GET` | `/products/{id}/prices` | 価格履歴全件を一覧表示する |
| `GET` | `/products/{id}/prices/current` | 現在有効な価格 |
| `GET` | `/products/{id}/prices/at` | 特定日時の価格（`?datetime=`） |

---

## 価格ティアモデル

各価格には `effective_from` と `effective_to` タイムスタンプがあります。ティアが「有効」なのは:

```
effective_from <= now  AND  (effective_to IS NULL  OR  effective_to > now)
```

`effective_to IS NULL` はティアにまだ終了日がないことを意味します（開区間）。

```sql
CREATE TABLE price_tiers (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id     INTEGER NOT NULL REFERENCES products(id),
    amount         INTEGER NOT NULL,       -- セント（非負）
    currency       TEXT    NOT NULL DEFAULT 'USD',
    effective_from TEXT    NOT NULL,
    effective_to   TEXT,                  -- NULL = 開区間（現在）
    created_at     TEXT    NOT NULL
);
```

---

## 価格設定: 古いティアを閉じ、新しいティアを開く

```php
public function setPrice(int $productId, int $amount, string $currency, string $effectiveFrom): PriceTier
{
    // 新しい effective_from より前に始まる開放ティアをすべて閉じる
    $this->db->execute(
        'UPDATE price_tiers
         SET effective_to = ?
         WHERE product_id = ? AND effective_to IS NULL AND effective_from <= ?',
        [$effectiveFrom, $productId, $effectiveFrom],
    );

    // 新しいティアを開く
    $id = $this->db->insert(
        'INSERT INTO price_tiers (product_id, amount, currency, effective_from, effective_to, created_at)
         VALUES (?, ?, ?, ?, NULL, ?)',
        [$productId, $amount, $currency, $effectiveFrom, $now],
    );
    // ...
}
```

UPDATE は `effective_from <= newEffectiveFrom` の開放ティアをすべて閉じます。これにより 3 つのシナリオを正しく処理します:
- **将来の effective_from**: 現在のティアを将来の日付で閉じます。
- **過去の effective_from**: 古いティアのクローズ日をさかのぼらせ、新しい過去ティアを開きます。
- **同じ effective_from**: 古いティアを同じ瞬間に閉じ（ゼロ期間）、新しいティアを開きます。

> **並行性の注意**: UPDATE と INSERT はトランザクションでラップされていません。同じ `effective_from` で 2 つの並行 `setPrice` 呼び出しが両方とも UPDATE フェーズを通過して両方 INSERT し、2 つの開放ティア（`effective_to IS NULL`）が残る可能性があります。クエリは `ORDER BY effective_from DESC LIMIT 1` を使用するため、最後の挿入が勝ちますが履歴が壊れます。並行性下での正確さには `transactional()` でラップしてください。

---

## 時点での価格照会

```php
public function priceAt(int $productId, string $datetime): ?PriceTier
{
    $row = $this->db->fetchOne(
        'SELECT * FROM price_tiers
         WHERE product_id = ? AND effective_from <= ?
           AND (effective_to IS NULL OR effective_to > ?)
         ORDER BY effective_from DESC
         LIMIT 1',
        [$productId, $datetime, $datetime],
    );

    return $row !== null ? $this->hydrateTier($row) : null;
}
```

比較は TEXT として保存された ISO 8601 日時の辞書順文字列比較です。**すべての日時が同じフォーマットとタイムゾーンを使用している場合のみ**正しく動作します（例: すべて UTC `2026-05-27 09:00:00`）。フォーマットやタイムゾーンオフセットを混在させると誤った結果になります。

**例**: `effective_from` が `"2026-05-27T09:00:00+09:00"`（JST）で `?datetime=2026-05-27T00:30:00Z`（UTC、同一瞬間）の場合、文字列比較はそれらを異なるものとして見なし、誤ったティアを返す可能性があります。書き込み時にすべての日時を UTC に正規化してください。

---

## セント単位の金額（整数）

浮動小数点の丸め誤差を避けるため、金額は整数（セント）で保存します:

```php
// POST /products/{id}/prices
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : null;

if ($amount === null || $amount < 0) {
    $errors[] = ['field' => 'amount', 'code' => 'required', 'message' => 'amount must be a non-negative integer (cents).'];
}
```

- `is_int()` は JSON の浮動小数点数（`9.99` → PHP の float）と文字列を拒否します。
- `$amount < 0` は負の価格を拒否します。
- `$amount === 0` は**許可**されています（無料商品 / プロモーション）。

---

## ATK — クラッカー攻撃テスト（FT228）

### ATK-01 — 認証なし

**Attack**: 認証情報なしで任意の商品の価格を設定する。

```http
POST /products/1/prices
{"amount": 1, "currency": "USD", "effective_from": "2026-01-01T00:00:00Z"}
```

**Observed**: `201 Created` — トークン不要。

**Verdict**: **EXPOSED**（FT67 デモでは設計上）。
本番では管理者ロールまたは API キーで価格変更を保護してください。

---

### ATK-02 — さかのぼり価格操作

**Attack**: `effective_from` を過去の日付に設定して価格履歴を変更する。

```json
{"amount": 0, "currency": "USD", "effective_from": "2020-01-01T00:00:00Z"}
```

**Observed**: `201 Created`。UPDATE が `2020-01-01` で既存の開放ティアを閉じ、2020 年以降のゼロ価格ティアが挿入されます。過去の日付の `priceAt` クエリがさかのぼった価格を返すようになります。

**Verdict**: **EXPOSED** — 認証なしではさかのぼりを認可するオーナーが存在しません。
認証があれば、呼び出し元が管理者でない限り `effective_from >= now()` を要求してください。

---

### ATK-03 — `?datetime=` 経由の SQL インジェクション

**Attack**: `datetime` クエリパラメーターを通じて SQL をインジェクションする。

```http
GET /products/1/prices/at?datetime=2026-01-01' OR '1'='1
```

**Observed**: `404 Not Found` — インジェクション文字列がパラメーター化された値として使用されるため、リテラル文字列が `effective_from` と比較され、何もマッチしません。

**Verdict**: **BLOCKED** — PDO パラメーター化ステートメントが SQL インジェクションを防ぎます。

---

### ATK-04 — ゼロ金額の価格

**Attack**: 商品価格をゼロ（無料）に設定する。

```json
{"amount": 0, "currency": "USD", "effective_from": "2026-05-27T00:00:00Z"}
```

**Observed**: `201 Created`。

**Verdict**: **ACCEPTED BY DESIGN** — `amount === 0` は意図的に許可されています（トライアルプラン、プロモーション）。`amount` はセントを意味し、0 は無料を意味することを文書化してください。ゼロ価格がドメインで有効でない場合は `$amount < 0` を `$amount <= 0` に変更してください。

---

### ATK-05 — 負の金額

**Attack**: 負の価格を設定する（返金攻撃？）。

```json
{"amount": -100, "currency": "USD", "effective_from": "2026-05-27T00:00:00Z"}
```

**Observed**: `422 Unprocessable Entity` — `$amount < 0` チェックが false を返します。

**Verdict**: **BLOCKED** — 負の金額はアプリケーション層で拒否されます。

---

### ATK-06 — 通貨コードインジェクション（許可リストなし）

**Attack**: 任意または悪意のある通貨文字列で価格を設定する。

```json
{"amount": 100, "currency": "NOTCURRENCY", "effective_from": "2026-05-27T00:00:00Z"}
{"amount": 100, "currency": "<script>alert(1)</script>", "effective_from": "..."}
{"amount": 100, "currency": "'; DROP TABLE price_tiers; --", "effective_from": "..."}
```

**Observed**: すべて `201 Created` を返します。通貨文字列はそのまま保存されます。SQL インジェクション文字列は安全（パラメーター化）ですが、`"NOTCURRENCY"` と XSS ペイロードは保存されます。

**Verdict**: **EXPOSED** — `currency` を ISO 4217 許可リストに対して検証してください:
```php
$validCurrencies = ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD'];
if (!in_array($currency, $validCurrencies, true)) {
    $errors[] = ['field' => 'currency', 'code' => 'invalid_value', 'message' => 'Unsupported currency code.'];
}
```

---

### ATK-07 — 極端に大きな金額

**Attack**: PHP/SQLite が処理できない金額を送信する。

```json
{"amount": 9999999999999999999, "currency": "USD", "effective_from": "..."}
```

**Observed**: PHP は `PHP_INT_MAX`（64 ビットで 2^63 - 1）を超える大きな JSON 整数を float として解析します。`is_int($body['amount'])` は float に対して false を返す → 422。

**Verdict**: **BLOCKED** — `is_int()` は PHP の float にオーバーフローする JSON 整数を正しく拒否します。`PHP_INT_MAX` 以内の値は SQLite 整数として正しく保存されます。

---

### ATK-08 — `?datetime=` の無効な日時

**Attack**: `priceAt` エンドポイントに日付以外の文字列を渡す。

```http
GET /products/1/prices/at?datetime=not-a-date
GET /products/1/prices/at?datetime=2026-02-30T00:00:00Z
```

**Observed**: どちらも `404 Not Found` を返します — 文字列が保存された `effective_from` 値と辞書順で比較され、何もマッチしません。例外はスローされません。

**Verdict**: **PARTIALLY EXPOSED** — エンドポイントが無効な日付を黙って受け入れ 404 を返すため、422 を期待する呼び出し元が混乱する可能性があります。フォーマットバリデーションを追加してください:
```php
$dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $datetime);
if ($dt === false) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'datetime', 'code' => 'invalid_format', 'message' => 'datetime must be ISO 8601.']],
    ]);
}
```

---

### ATK-09 — 将来の effective_from（価格のスケジューリング）

**Attack**: `effective_from` を将来の日付に設定して価格変更をスケジューリングする。

```json
{"amount": 999, "currency": "USD", "effective_from": "2099-12-31T00:00:00Z"}
```

**Observed**: `201 Created`。`currentPrice()` は以前の価格を返し続けますが（将来のティアの `effective_from > now`）、`priceAt("2099-12-31T01:00:00Z")` は新しいティアを返します。

**Verdict**: **ACCEPTED BY DESIGN** — スケジュール価格設定は正当なユースケースです。API 仕様に文書化してください。スケジューリングを管理者に制限すべきであれば、認証を要求し非管理者の呼び出し元に `effective_from <= now + 30 days` をチェックしてください。

---

### ATK-10 — 並行価格設定（レースコンディション）

**Attack**: 同じ `effective_from` で 2 つの `POST /products/1/prices` を同時に送信する。

**Observed**: UPDATE + INSERT をラップするトランザクションなしでは、両方のリクエストが UPDATE フェーズを通過して両方 INSERT し、2 つの開放ティア（`effective_to IS NULL`）が作成される可能性があります。クエリは `ORDER BY effective_from DESC LIMIT 1` を使用するため、結果は非決定的です。

**Verdict**: **EXPOSED** — `setPrice` を `transactional()` でラップしてください:
```php
return $this->txManager->transactional(function ($tx) use (...) {
    // トランザクション内で UPDATE してから INSERT
});
```

---

### ATK-11 — 存在しない product_id

**Attack**: 存在しない商品の価格を設定する。

```http
POST /products/99999/prices
{"amount": 100, "currency": "USD", "effective_from": "2026-05-27T00:00:00Z"}
```

**Observed**: `404 Not Found` — `findProduct(99999)` が `null` を返し、コントローラーが `setPrice` を呼ぶ前に not-found Problem Details レスポンスを返します。

**Verdict**: **BLOCKED** — 変更前の存在チェック。

---

### ATK-12 — パス ID に非数値文字列

**Attack**: `{id}` に数字以外の文字列を渡す。

```http
GET /products/abc
GET /products/-1
POST /products/0/prices
```

**Observed**: すべて `404 Not Found` を返します。`(int) "abc"` = `0`; `findProduct(0)` は `null` を返します（ID 0 の商品なし）; コントローラーが 404 を返します。

**Verdict**: **BLOCKED**（実際には）。注意: `(int) "9abc"` = `9` — ID 9 の商品がある場合マッチします。厳密なパスバリデーションが必要な場合は `ctype_digit()` を使ってください。

---

## ATK サマリー

| # | 攻撃ベクター | 判定 |
|---|---------------|---------|
| ATK-01 | 認証なし | EXPOSED（設計上） |
| ATK-02 | さかのぼり価格操作 | EXPOSED |
| ATK-03 | `?datetime=` 経由の SQL インジェクション | BLOCKED |
| ATK-04 | ゼロ金額の価格 | ACCEPTED BY DESIGN |
| ATK-05 | 負の金額 | BLOCKED |
| ATK-06 | 通貨コードインジェクション（許可リストなし） | EXPOSED |
| ATK-07 | 極端に大きな金額 | BLOCKED |
| ATK-08 | 無効な日時フォーマット | PARTIALLY EXPOSED |
| ATK-09 | 将来の `effective_from`（スケジュール価格） | ACCEPTED BY DESIGN |
| ATK-10 | 並行 setPrice レースコンディション | EXPOSED |
| ATK-11 | 存在しない商品 | BLOCKED |
| ATK-12 | パス ID に非数値文字列 | BLOCKED |

**本番前に修正すべき実際の脆弱性**:
1. **ATK-01** — 認証/認可を追加する
2. **ATK-02** — さかのぼりを管理者呼び出し元に制限する（または完全に禁止）
3. **ATK-06** — `currency` を ISO 4217 許可リストに対して検証する
4. **ATK-08** — DB クエリ前に `?datetime=` フォーマットを検証する
5. **ATK-10** — `setPrice` の UPDATE+INSERT をトランザクションでラップする

---

## 関連ハウツー

- [`expense-tracker.md`](expense-tracker.md) — `is_int()` 金額バリデーションと ISO 8601 日付ラウンドトリップ
- [`habit-tracker.md`](habit-tracker.md) — ATK-01〜12 パターン（前の ATK サイクル）
- [`prevent-double-booking.md`](prevent-double-booking.md) — トランザクション的な読み取り-チェック-書き込み
- [`iso-datetime-validation.md`](iso-datetime-validation.md) — 厳密な ISO 8601 バリデーション
