# ハウツー: 整数セントを使ったマルチ通貨金銭台帳

> **FT リファレンス**: FT262 (`NENE2-FT/moneylog`) — 整数最小単位（セント）と `Money` バリューオブジェクトを使ったマルチ通貨台帳 API

浮動小数点の精度エラーを避けるために金額を整数最小単位（セント、円、ペンス）として保存する複式簿記スタイルの台帳 API を実証します。`Money` バリューオブジェクトが不変条件を強制します: 正の金額と 3 文字の ISO 4217 通貨コード。通貨ごとの残高は単一の SQL クエリで `SUM(CASE WHEN type = 'credit' ...)` を使って計算されます。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/entries` | 台帳エントリーを作成する（クレジットまたはデビット） |
| `GET` | `/entries` | エントリーを一覧表示する（ページネーション） |
| `GET` | `/entries/{id}` | 単一エントリーを取得する |
| `GET` | `/balance` | 通貨ごとの残高（クレジット − デビット） |

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS entries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    description  TEXT    NOT NULL,
    amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
    currency     TEXT    NOT NULL CHECK(length(currency) = 3),
    type         TEXT    NOT NULL CHECK(type IN ('credit', 'debit')),
    created_at   TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_entries_currency ON entries(currency);
CREATE INDEX IF NOT EXISTS idx_entries_created  ON entries(created_at);
```

`CHECK(amount_cents > 0)` は DB レベルで正の金額を強制します — バグや DB への直接アクセスに対するセーフティネットです。`CHECK(length(currency) = 3)` は ISO 4217 形式を強制します。`CHECK(type IN ('credit', 'debit'))` は無効な状態を防止します。

---

## なぜ float ではなく整数セントなのか？

```php
// ❌ 浮動小数点演算は精度を失う
var_dump(0.1 + 0.2);  // float(0.30000000000000004)

// ✅ 整数演算は正確
$total = 10 + 20;     // int(30) — 常に正確
```

`FLOAT` として保存された金額は合計全体で丸め誤差を蓄積し、`===` で信頼できる比較ができません。整数最小単位（USD/EUR のセント、JPY の円）は常に正確です。表示変換（`$cents / 100.0`）はビジネスロジックではなくシリアライゼーション時にのみ行われます。

**注意**: `JPY` などのゼロ小数点通貨は全体の単位を「セント」として保存します（つまり ¥1000 = 1000 セント）。この FT の `formatDecimal()` は固定で 2 小数点デフォルトを使います。本番実装では通貨の小数点桁数を調べる必要があります。

---

## `Money` バリューオブジェクト

```php
final readonly class Money
{
    public function __construct(
        public int    $amountCents,
        public string $currency,
    ) {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException("amountCents must be positive, got {$amountCents}.");
        }
        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException("currency must be a 3-character ISO 4217 code, got '{$currency}'.");
        }
    }

    public function formatDecimal(): string
    {
        return number_format($this->amountCents / 100, 2, '.', '');
    }
}
```

コンストラクターは自身の不変条件をバリデーションします。存在する `Money` オブジェクトは常に有効です — 呼び出し元は値を再チェックする必要がありません。`readonly` は構築後の変異を防止します。

`formatDecimal()` は表示のみに使います。フォーマット済みの文字列を保存または比較しないでください。常に `amountCents` 整数を比較してください。

---

## `EntryType` backed enum

```php
enum EntryType: string
{
    case Credit = 'credit';
    case Debit  = 'debit';
}
```

ハイドレーションでの `EntryType::from('credit')` が DB の文字列を enum に変換します。DB が何らかの理由で予期しない値を含んでいる場合、`from()` はスロー — サイレントな破損なし。

コントローラーでの `EntryType::tryFrom($value)` は未知の値に対して `null` を返し、バリデーションエラーチェックがそれをキャッチします:

```php
$type = $typeValue !== null ? EntryType::tryFrom($typeValue) : null;
if ($type === null) {
    $errors[] = new ValidationError('type', "type must be 'credit' or 'debit'.", 'invalid');
}
```

---

## 通貨ごとの残高: `SUM(CASE WHEN ...)`

```php
public function balanceByCurrency(): array
{
    $rows = $this->executor->fetchAll(
        "SELECT currency,
            SUM(CASE WHEN type = 'credit' THEN amount_cents ELSE 0 END) AS credit_cents,
            SUM(CASE WHEN type = 'debit'  THEN amount_cents ELSE 0 END) AS debit_cents,
            SUM(CASE WHEN type = 'credit' THEN amount_cents ELSE -amount_cents END) AS balance_cents
         FROM entries
         GROUP BY currency
         ORDER BY currency ASC",
        [],
    );
    // ...
}
```

単一クエリが通貨ごとに 3 つの集計を計算します:
- `credit_cents`: クレジット合計
- `debit_cents`: デビット合計
- `balance_cents`: 純残高（`クレジット − デビット`）

`CASE WHEN type = 'credit' THEN amount_cents ELSE -amount_cents END` は符号反転を使って 1 回のパスで純額を計算します。負の `balance_cents` はデビットがクレジットを超えていることを意味します。

**代替**: 2 つのクエリ（`SELECT SUM WHERE type = 'credit'` と `SELECT SUM WHERE type = 'debit'`）を PHP でマージ。単一クエリアプローチはより効率的で、SQL で引き算を維持します。

---

## コントローラー: 通貨の正規化

```php
$money = new Money(
    (int) $body['amount_cents'],
    strtoupper((string) $body['currency']),  // ← 大文字に正規化
);
```

`strtoupper()` は通貨コードを正規化するので `usd`、`USD`、`Usd` はすべて `USD` として保存されます。正規化なしでは `USD` と `usd` は残高クエリで別々の通貨として表示されます。

---

## シリアライゼーション: セントと小数の両方

```php
private function serialize(Entry $entry): array
{
    return [
        'id'           => $entry->id,
        'description'  => $entry->description,
        'amount_cents' => $entry->money->amountCents,   // 機械可読: 正確な整数
        'amount'       => $entry->money->formatDecimal(), // 人間可読: "10.50"
        'currency'     => $entry->money->currency,
        'type'         => $entry->type->value,
        'created_at'   => $entry->createdAt,
    ];
}
```

`amount_cents`（整数）と `amount`（フォーマット済み小数）の両方が返されます。計算を実行するクライアントは `amount_cents` を使うべきです。表示 UI は `amount` を使っても構いません。

---

## 例: 残高レスポンス

**リクエスト**: `GET /balance`

```json
{
  "balances": [
    {"currency": "EUR", "credit_cents": 50000, "debit_cents": 20000, "balance_cents": 30000},
    {"currency": "JPY", "credit_cents": 100000, "debit_cents": 0, "balance_cents": 100000},
    {"currency": "USD", "credit_cents": 150000, "debit_cents": 75000, "balance_cents": 75000}
  ]
}
```

EUR 残高: 500.00 − 200.00 = 300.00 EUR。USD 残高: 1500.00 − 750.00 = 750.00 USD。

---

## 設計比較

| ストレージアプローチ | 精度 | トレードオフ |
|----------------|------|---------|
| `INTEGER` セント | 正確 | 表示変換が必要; 通貨が小数点桁数を指定しなければならない |
| `DECIMAL(19,4)` | 正確 | DB ネイティブ; SQLite では利用不可; 表示用にフォーマット |
| `FLOAT`/`REAL` | 損失あり | 金額には決して使わない — 丸め誤差が蓄積する |
| `TEXT` ("10.50") | N/A | ソートと合計にキャストが必要; SQL での演算なし |

SQLite の `INTEGER` とセントは SQLite バックエンド API の最もシンプルで安全なアプローチです。MySQL/PostgreSQL では `DECIMAL(19,4)` がより一般的です。

---

## 関連 howto

- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — 資金移動のためのアトミックマルチライト
- [`bulk-operations-partial-success.md`](bulk-operations-partial-success.md) — 部分的成功を伴うバルクエントリーインポート
- [`leaderboard-ranking-api.md`](leaderboard-ranking-api.md) — SQL ウィンドウ関数を使った集計クエリ
