# ハウツー: 集計レポート API

> **FT リファレンス**: FT245 (`NENE2-FT/agglog`) — 集計レポート API

1 つの注文テーブルをサマリー合計、日次ブレークダウン、ステータス分布、上位アイテムに分割する多次元集計レポート API を示します — オプションの日付範囲フィルタリング、ゼロセーフ集計のための `COALESCE`、サブクエリなしの条件付きカウントに `COUNT(CASE WHEN...)` を使用します。

---

## ルート

| メソッド | パス | 説明 |
|--------|----------------------|------------------------------------------------------|
| `POST` | `/orders` | 注文を記録する |
| `GET` | `/reports/summary` | 注文合計、収益、平均注文額、完了数 |
| `GET` | `/reports/daily` | 日ごとの収益と注文数 |
| `GET` | `/reports/by-status` | ステータスごとにグループ化した注文数と収益 |
| `GET` | `/reports/top-items` | 収益による上位アイテム（制限付き、ランク付き） |

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id TEXT    NOT NULL,
    item_name   TEXT    NOT NULL,
    amount      INTEGER NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending'
                    CHECK(status IN ('pending', 'completed', 'refunded', 'cancelled')),
    created_at  TEXT    NOT NULL
);
```

`status` は安全ネットとして DB レベルで `CHECK` によって制約されます。`amount` は整数で保存されます（最小通貨単位）。`created_at` は ISO 文字列で — 日付の比較は `YYYY-MM-DD` フォーマットの文字列順序を使用し、これは辞書順が時系列順と一致します。

---

## サマリー集計: `COALESCE` + `COUNT(CASE WHEN ...)`

サマリーエンドポイントは 1 つのクエリでいくつかの集計メトリクスを返します:

```php
$row = $this->db->fetchOne(
    "SELECT COUNT(*) AS total_orders,
            COALESCE(SUM(amount), 0) AS total_revenue,
            COALESCE(AVG(amount), 0) AS avg_order_value,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_orders
     FROM orders {$where}",
    $params,
);
```

`COALESCE(SUM(amount), 0)` — テーブルに一致する行がない場合 `NULL` の代わりに `0` を返します。`SUM()` と `AVG()` は空のセットで `NULL` を返します；`COALESCE` がこれを安全なゼロに変換します。

`COUNT(CASE WHEN status = 'completed' THEN 1 END)` — サブクエリや二重スキャンなしに `status = 'completed'` の行のみをカウントします。`CASE WHEN` は非一致行に `NULL` を返し；`COUNT` は `NULL` を無視するため、完了した注文のみがカウントされます。

これはフィルタリングされた `COUNT` と同等ですが、単一スキャンで実行されるため、各ステータスの個別クエリよりも効率的です。

---

## 日次ブレークダウン: 日付切り捨てのための `substr()`

```php
$rows = $this->db->fetchAll(
    "SELECT substr(created_at, 1, 10) AS date,
            COUNT(*) AS order_count,
            SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY date
     ORDER BY date ASC",
    $params,
);
```

`substr(created_at, 1, 10)` は ISO 日時文字列から最初の 10 文字（`YYYY-MM-DD`）を抽出し、同じ暦日のすべてのイベントをグループ化します。これは固定プレフィックスの ISO 8601 フォーマットのタイムスタンプ文字列に対する SQLite の `strftime('%Y-%m-%d', created_at)` の代替です。

`GROUP BY date` はエイリアスを使用します — SQLite は `GROUP BY` でのエイリアスをサポートします（式の繰り返しを要求する一部のデータベースとは異なります）。

---

## ステータス分布: `GROUP BY status ORDER BY count DESC`

```php
$rows = $this->db->fetchAll(
    "SELECT status, COUNT(*) AS order_count, SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY status
     ORDER BY order_count DESC",
    $params,
);
```

`ORDER BY order_count DESC` で最も一般的なステータスを先頭に配置します。結果セットは最大でもスキーマ内の異なるステータス値の数（このスキーマでは 4 つ）の行しか持ちません。

---

## 上位アイテム: `LIMIT` による収益ランキング

```php
$rows = $this->db->fetchAll(
    "SELECT item_name, COUNT(*) AS order_count, SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY item_name
     ORDER BY revenue DESC
     LIMIT ?",
    $params,
);
```

`ORDER BY revenue DESC LIMIT ?` — パラメータ化された `LIMIT` で総収益による上位 N 件のアイテムを選択します。`limit` パスパラメーターはサーバー側でクランプされます:

```php
private const int MAX_LIMIT = 100;

$limit = min((int) $q['limit'], self::MAX_LIMIT);
```

`min(..., MAX_LIMIT)` でクライアントが 100 件を超えるアイテムをリクエストできないようにします。注意: クエリ文字列の値は常に文字列なので、ここでは `is_int` の代わりに `is_numeric($q['limit'])` を使用します — `is_int` はクエリ文字列の入力では常に失敗します。

---

## `dateFilter()` による動的 `WHERE` 句

すべての集計クエリは、日付の境界が提供された場合のみ条件を追加する `dateFilter()` ヘルパーを共有します:

```php
private function dateFilter(?string $from, ?string $to): array
{
    $conditions = [];
    $params     = [];
    if ($from !== null) {
        $conditions[] = 'created_at >= ?';
        $params[]     = $from;
    }
    if ($to !== null) {
        $conditions[] = 'created_at <= ?';
        $params[]     = $to;
    }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
    return [$where, $params];
}
```

`from` と `to` の両方が `null` の場合、`$where` は `''` — テーブル全体がスキャンされます。呼び出し元はクエリが実行される前に `{$where}` を SQL 文字列に埋め込みます。実際の値は依然としてパラメータ化（`?`）されており、`WHERE` キーワードのみが補間されます。

---

## 日付バリデーション: `createFromFormat()` ラウンドトリップ

`from` と `to` を `YYYY-MM-DD` 文字列として受け入れるには、日付が適切な形式で意味論的にも有効であることを検証する必要があります（例: `2026-02-30` は拒否）:

```php
private function isValidDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}
```

2 段階のバリデーション:
1. `preg_match` — 日付オブジェクトのオーバーヘッドなしに不一致フォーマットを素早く拒否。
2. `createFromFormat` + ラウンドトリップ `format()` — `2026-02-30`（正規表現のみでバリデーションした場合 PHP が `2026-03-02` にオーバーフローさせる）のような意味論的に無効な日付を検出。

範囲の方向もバリデーションされます:
```php
if ($from !== null && $to !== null && $from > $to) {
    $errors[] = new ValidationError('from', 'from must be before or equal to to', 'invalid');
}
```

両方の日付が `YYYY-MM-DD` フォーマットであるため、文字列比較はここでも正しく機能します — 辞書順と時系列順が一致するフォーマットです。

---

## 使用する `NENE2` ビルトイン

| ビルトイン | 用途 |
|---|---|
| `ValidationException` / `ValidationError` | `errors` 配列付きの構造化 `422` |
| `JsonResponseFactory::create()` | JSON レスポンスをエンコードする |
| `Router` 定数 | パスパラメーター用の `PARAMETERS_ATTRIBUTE` |

---

## 関連ハウツー

- [`event-analytics-api.md`](event-analytics-api.md) — `json_extract()` と `COUNT(DISTINCT)` グループ化による JSON ブロブ分析
- [`cqrs-pattern.md`](cqrs-pattern.md) — 注文集計の読み取りモデルとしての SQL VIEW
- [`credit-ledger.md`](credit-ledger.md) — `COALESCE(SUM(amount * direction), 0)` による残高計算
- [`admin-report-aggregation.md`](admin-report-aggregation.md) — 管理者スコープの集計パターン
