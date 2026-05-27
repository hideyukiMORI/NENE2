# ハウツー: 金額と整数演算

> **関連シナリオ**: DX シナリオ 10、23、32、36、40、43、44、50 — 財務シナリオ全般で最も頻繁に挙げられる暗黙の精度バグの原因。

浮動小数点（`REAL` / `float`）として保存された金額は丸め誤差を蓄積します。IEEE 754 での `1001 * 0.05` は `50.05` ではなく `50.049999999999997` を生成します。正しいアプローチは金額を**最小通貨単位の整数**として保存・計算することです（JPY は円、USD/EUR はセント）。

---

## ルール: 常に整数として保存する

```php
// ❌ 間違い — REAL/float は誤差を蓄積する
$fee = $amount * 0.05;           // 1001 * 0.05 = 50.04999...
$tax = $price * 1.10;            // 1000 * 1.10 = 1100.0000000000002

// ✅ 正しい — 整数演算
$fee = intdiv($amount * 5, 100); // 1001 * 5 / 100 = 50（切り捨て）
$tax = intdiv($amount * 110, 100); // 1000 * 110 / 100 = 1100
```

スキーマ:

```sql
CREATE TABLE orders (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    amount_yen   INTEGER NOT NULL CHECK(amount_yen > 0),  -- ✅ REAL ではなく INTEGER
    fee_yen      INTEGER NOT NULL CHECK(fee_yen >= 0),
    tax_yen      INTEGER NOT NULL CHECK(tax_yen >= 0),
    total_yen    INTEGER NOT NULL CHECK(total_yen > 0)
);
```

DB レベルで非負の値を強制するために `CHECK` 制約を使ってください。

---

## 丸め関数の選択

整数を割るとき、余りの処理を決定する必要があります。**コードを書く前にこのポリシーを決定してドキュメント化してください** — 後で変更すると、すべての履歴レコードに影響します。

| 関数 | 動作 | 例: `intdiv(1, 3)` | 使用タイミング |
|------|------|-------------------|-------------|
| `intdiv($a, $b)` | ゼロに向かって切り捨て | `0` | プラットフォーム手数料（支払者が余りを保持） |
| `(int) round($a / $b)` | 四捨五入 | `0`（0 に丸め） | 割り勘、汎用丸め |
| `(int) ceil($a / $b)` | 切り上げ（天井） | `1` | 税計算（政府向けは常に切り上げ） |
| `(int) floor($a / $b)` | 切り下げ（床） | `0` | 正の値では intdiv と同じ |

### プラットフォーム手数料（5%） — 誰が余りを保持するか？

```php
// オプション A: プラットフォームが floor を取る（支払者優遇）
$fee = intdiv($amount * 5, 100);     // 1001 円 → 手数料 = 50、売り手は 951 円

// オプション B: プラットフォームが ceil を取る（プラットフォーム優遇）
$fee = (int) ceil($amount * 5 / 100); // 1001 円 → 手数料 = 51、売り手は 950 円

// オプション C: 四捨五入（中立）
$fee = (int) round($amount * 5 / 100); // 1001 円 → 手数料 = 50、売り手は 951 円
```

普遍的に正しい答えはありません。**API 仕様でその選択をドキュメント化してください。**

---

## 税計算（日本の消費税: 10%）

日本の消費税は**取引ごとに切り捨て**が必要です（明細ごとではない）:

```php
// ✅ 取引レベルで切り捨て
$taxIncluded  = intdiv($priceExcl * 110, 100);  // 1000 → 1100
$taxAmount    = intdiv($priceExcl * 10, 100);   // 1000 → 100

// ❌ 明細ごとに丸めてから合計しない — 丸め誤差が蓄積する
$items = [100, 100, 100]; // 3 品目 × 100 円
$total = array_sum(array_map(fn($p) => (int)round($p * 1.1), $items)); // intdiv(300 * 110, 100) と異なる場合がある
```

`tax_rate` を保存する場合は**ベーシスポイント**（整数、1/10000）として保存してください:
`10% = 1000 bps`。レート保存自体での浮動小数点を避けます。

```sql
tax_rate_bps INTEGER NOT NULL DEFAULT 1000  -- 10.00%
```

```php
$taxAmount = intdiv($amount * $taxRateBps, 10000);
```

---

## 割り勘: 余りの配分

N 人で合計を割り勘にする場合:

```php
function splitEvenly(int $totalYen, int $n): array
{
    $base      = intdiv($totalYen, $n);       // 各人のシェア（切り捨て）
    $remainder = $totalYen % $n;              // 余りの円（0 から n-1）

    $shares = array_fill(0, $n, $base);

    // 余りを最初の参加者に 1 円ずつ配分する
    for ($i = 0; $i < $remainder; $i++) {
        $shares[$i]++;
    }

    // 検証: 合計は元の合計と一致しなければならない
    assert(array_sum($shares) === $totalYen);

    return $shares;
}

// splitEvenly(1000, 3) → [334, 333, 333]  (合計 = 1000) ✅
// splitEvenly(100,  3) → [34,  33,  33]   (合計 = 100)  ✅
```

各参加者に `round($total / $n)` を使って完了としないでください — 合計が 1 円ずれることが多いです。

---

## SQLite の整数除算のトラップ

SQLite では、2 つの整数を割ると整数除算が行われます:

```sql
SELECT 5 / 100;     -- → 0  （整数除算: 切り捨て）
SELECT 5.0 / 100;   -- → 0.05（実数除算）
SELECT 5 * 100 / 100;  -- → 5（先に掛け算してから割り算 — OK）
```

PDO を使った **PHP** では、すべてのバインド値は文字列として送信されます。SQLite はそれらを変換しますが:

```php
// 安全: 切り捨てを避けるために先に掛け算する
$fee = $this->db->fetchOne(
    'SELECT amount_yen * 5 / 100 AS fee FROM orders WHERE id = ?',
    [$id],
);
// → amount_yen * 5 が先（整数 × 整数 = 整数）、次に / 100

// リスクあり: PDO が '5' と '100' を文字列として送ると、SQLite が実数除算を選ぶ場合がある
// SQLite のバージョンや PDO の動作が不明な場合はテストしてください。
```

最も安全なアプローチ: **PHP の `intdiv()` で演算を行い**、結果を保存し、行ごとの計算ではなく合計（`SUM`、`COUNT`）のみに SQL 演算を使ってください。

---

## 減価償却（定額法）

```php
// 年間減価償却（定額法）
$annualDepr = intdiv($purchasePrice - $salvageValue, $usefulLifeYears);

// 現在の帳簿価額
$yearsElapsed = (int) floor(
    (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->diff(
        new \DateTimeImmutable($purchaseDateUtc)
    )->days / 365
);
$currentValue = max($salvageValue, $purchasePrice - $annualDepr * $yearsElapsed);
```

`intdiv` は年間減価償却を切り捨てます。これはアセットが年ごとにわずかに少なく減価償却され、余りが最終年の余分な減価償却として現れることを意味します。これは日本の定額法減価償却の標準的な動作です。

---

## ユーザーへの表示

人が読める形式への変換はレスポンス層でのみ行い、ドメインでは行わないでください:

```php
final readonly class MoneyResponse
{
    public function __construct(
        public int    $amountYen,
        public string $displayAmount,  // "¥1,234"
    ) {}

    public static function fromYen(int $yen): self
    {
        return new self(
            amountYen:     $yen,
            displayAmount: '¥' . number_format($yen),
        );
    }
}
```

追加計算のために `amountYen`（整数）を保存し、UI のために `displayAmount`（文字列）を使ってください。フォーマット済みの文字列を保存しないでください — それらは合計できません。

---

## サマリー: 判断チェックリスト

金額計算を書く前に以下の質問に答えてください:

1. **単位**: 円（小数なし）、セント（1/100）、マイクロペニー（1/1000）?
   → その単位の整数として保存し、カラム名に単位をドキュメント化する（`amount_yen`、`price_cents`）。

2. **丸め方向**: `intdiv`（切り捨て）、`ceil`、`floor`、または `round`?
   → 1 つ選択し、コードにそれがなぜかをコメントで追加する。

3. **誰が余りを受け取るか**: 割り勘のとき、誰が丸め差を吸収するか?
   → 余りを明示的に配分する（上の `splitEvenly` を参照）。

4. **税率の保存**: パーセント（`REAL`）ではなくベーシスポイント（`INTEGER`）?
   → 10% は `1000`、8% は `800`、`0.10` や `0.08` は絶対に使わない。

5. **累積か取引ごとか**: 明細ごとに税を累積するか、請求書合計ごとか?
   → 取引ごと（単一の `intdiv`）が JPY 請求書の標準。

---

## 関連 howto

- [`multi-currency-money-ledger.md`](multi-currency-money-ledger.md) — `Money` バリューオブジェクトを使った複式簿記台帳
- [`point-ledger-api.md`](point-ledger-api.md) — 整数金額を使ったポイント/クレジットシステム
- [`expense-tracking-api.md`](expense-tracking-api.md) — 整数円による経費記録
