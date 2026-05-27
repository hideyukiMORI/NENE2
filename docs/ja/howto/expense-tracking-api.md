# ハウツー: 経費追跡 API

> **FT リファレンス**: FT311 (`NENE2-FT/expenselog`) — 経費追跡: YYYY-MM-DD 日付フォーマットバリデーション、カテゴリー文字列（オープン、enum なし）、カテゴリー別月次サマリー集計、limit/offset によるオフセットページネーション、PATCH 部分更新（提供されたフィールドのみ変更）、日付範囲フィルター、動的 `/{id}` より前の静的 `/summary` ルート、34 テスト / 67 アサーション PASS。

このガイドでは、日付フィルタリング、カテゴリー集計、ページネーション、部分更新を持つ経費追跡 API の構築方法を示します。

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS expenses (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    date        TEXT    NOT NULL,  -- YYYY-MM-DD
    amount      INTEGER NOT NULL,  -- セント
    category    TEXT    NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_expenses_date     ON expenses (date);
CREATE INDEX IF NOT EXISTS idx_expenses_category ON expenses (category);
```

`date` と `category` のインデックスが高速フィルタリングをサポートします。整数セントの `amount` は浮動小数点の精度問題を避けます。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `GET` | `/expenses` | オプションフィルター + ページネーションで一覧表示 |
| `POST` | `/expenses` | 経費を作成する |
| `GET` | `/expenses/summary` | 月次カテゴリー集計 |
| `GET` | `/expenses/{id}` | 単一経費を取得する |
| `PATCH` | `/expenses/{id}` | 部分更新 |
| `DELETE` | `/expenses/{id}` | 削除する |

**ルート順序**: `/expenses/summary` は `/expenses/{id}` の**前に**登録する必要があります — さもないと `summary` が `id` パラメーターとしてキャプチャされます。

## 日付バリデーション — YYYY-MM-DD

```php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $errors[] = new ValidationError('date', 'date must be in YYYY-MM-DD format.', 'invalid_format');
}
```

厳格な `YYYY-MM-DD` フォーマットのみ受け付けます。時刻コンポーネントやタイムゾーンオフセットを含む ISO 8601 文字列は拒否されます。

## カテゴリー — オープン文字列（enum なし）

```php
$category = isset($body['category']) && is_string($body['category']) && $body['category'] !== ''
    ? $body['category']
    : null;
if ($category === null) {
    $errors[] = new ValidationError('category', 'category is required.', 'required');
}
```

カテゴリーは自由形式の文字列です（クローズド enum ではありません）。空でない文字列はすべて有効で、スキーマ変更なしに `"food"`、`"transport"`、`"entertainment"` のようなカテゴリーを許可します。

## 月次サマリー — YYYY-MM フォーマット

```php
// クエリ: SELECT category, SUM(amount), COUNT(*) WHERE date LIKE 'YYYY-MM-%'
$summary = $this->repository->monthlySummary($month);

return $this->json->create([
    'month'       => $summary->month,
    'total'       => $summary->total,
    'count'       => $summary->count,
    'by_category' => $summary->byCategory, // [{category, total, count}, ...]
]);
```

月パラメーターフォーマット: `YYYY-MM`。その月のすべての経費をカテゴリーごとにグループ化して集計します。

## ページネーション — オフセットベース

```php
$pagination = PaginationQueryParser::parse($request);
// 返値: { limit: int, offset: int }

$items = $this->repository->findAll($from, $to, $category, $pagination->limit, $pagination->offset);
$total = $this->repository->count($from, $to, $category);

return $this->json->create([
    'items'  => $items,
    'total'  => $total,
    'limit'  => $pagination->limit,
    'offset' => $pagination->offset,
]);
```

無効な `limit`（非整数、負、大きすぎる）→ 422。

## PATCH — 部分更新

```php
// ボディに存在するフィールドのみ更新する
$date     = array_key_exists('date', $body)     ? $body['date']     : null;
$amount   = array_key_exists('amount', $body)   ? $body['amount']   : null;
$category = array_key_exists('category', $body) ? $body['category'] : null;
$note     = array_key_exists('note', $body)     ? $body['note']     : null;
```

`array_key_exists()` は「フィールドが未提供」と「フィールドが null として提供」を区別します。提供されたフィールドのみがバリデーションと更新の対象になります。

## 日付範囲フィルター

```php
// GET /expenses?date_from=2026-01-01&date_to=2026-01-31
$from = QueryStringParser::string($request, 'date_from');
$to   = QueryStringParser::string($request, 'date_to');
$category = QueryStringParser::string($request, 'category');

$items = $this->repository->findAll($from, $to, $category, $limit, $offset);
```

すべてのフィルターパラメーターはオプションです。null はその次元でフィルターなしを意味します。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| `/expenses/{id}` より前に `/expenses/summary` を登録しない | `"summary"` が `id` としてマッチする。summary エンドポイントに到達できない |
| `amount` を FLOAT として保存する | 浮動小数点精度: `0.1 + 0.2 ≠ 0.3`。整数セントを使用する |
| 任意の日付文字列を受け付ける（時刻付き ISO 8601） | WHERE 句での不整合な日付比較 |
| クローズドカテゴリー enum | 新しいカテゴリーにスキーママイグレーションが必要 |
| PATCH に `isset($body['field'])` を使用 | `isset()` は `null` に対して false を返す。`array_key_exists()` を使用する |
| 一覧と同じフィルターなしの COUNT クエリ | ページネーションの合計が実際のフィルタリングされたカウントと一致しない |
| date/category のインデックスなし | フィルタリングされたすべての一覧リクエストでフルテーブルスキャン |
