# ハウツー: 経費トラッカー API

このガイドでは、NENE2 を使ってカテゴリーベースのフィルタリング、日付範囲クエリ、月次サマリー集計、完全な CRUD を持つ個人経費追跡システムの構築方法を示します。
**expenselog** フィールドトライアル（FT223）で実証されたパターンです。

## 機能

- 経費の作成、読み取り、更新、削除（日付、金額、カテゴリー、メモ）
- 日付範囲フィルター（`?from=` / `?to=`）とカテゴリーフィルターを使った一覧
- 月次サマリー集計（月ごとのカテゴリーごとの合計）
- 合計カウント付きページネーション
- カテゴリー許可リストバリデーション
- 金額バリデーション: 正の整数（セント）

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS expenses (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    date       TEXT    NOT NULL,
    amount     INTEGER NOT NULL,
    category   TEXT    NOT NULL,
    note       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_expenses_date ON expenses (date DESC);
CREATE INDEX IF NOT EXISTS idx_expenses_category ON expenses (category, date DESC);
```

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `GET` | `/expenses` | 経費を一覧表示する（フィルタリング可能、ページネーション） |
| `POST` | `/expenses` | 経費を作成する |
| `GET` | `/expenses/summary` | カテゴリー別月次サマリー |
| `GET` | `/expenses/{id}` | 単一経費を取得する |
| `PATCH` | `/expenses/{id}` | 部分更新 |
| `DELETE` | `/expenses/{id}` | 経費を削除する |

## バリデーションパターン

### 金額（正の整数、セントで保存）

```php
if (!is_int($amount) || $amount <= 0) {
    $errors[] = new ValidationError('amount', 'Amount must be a positive integer (cents).');
}
```

`is_int()` を使うことで JSON からの浮動小数点（`1.5` は PHP の strict モードでは int ではない）を拒否します。

### 日付（ISO 8601 フォーマット）

```php
$date = DateTime::createFromFormat('Y-m-d', $dateStr);
if (!$date || $date->format('Y-m-d') !== $dateStr) {
    $errors[] = new ValidationError('date', 'date must be a valid YYYY-MM-DD date.');
}
```

ラウンドトリップバリデーション: パースして再フォーマット — 文字列が正規化されていたことを保証します。

### カテゴリー許可リスト

```php
private const array VALID_CATEGORIES = [
    'food', 'transport', 'utilities', 'entertainment',
    'health', 'shopping', 'travel', 'other',
];

if (!in_array($category, self::VALID_CATEGORIES, true)) {
    $errors[] = new ValidationError('category', 'Invalid category.');
}
```

## 日付範囲フィルタリング

```php
public function findAll(
    ?string $from = null,
    ?string $to = null,
    ?string $category = null,
    int $limit = 20,
    int $offset = 0,
): array
```

フィルターはオプションです — 全期間クエリには省略します。日付は辞書的に比較されます（ISO 8601 文字列は UTC で並べ替え可能）。

## 月次サマリークエリ

SQLite の `strftime` を使って年月でグループ化します:

```sql
SELECT strftime('%Y-%m', date) AS month, category, SUM(amount) AS total, COUNT(*) AS count
FROM expenses
WHERE date BETWEEN :from AND :to
GROUP BY month, category
ORDER BY month DESC, category ASC
```

各月のカテゴリーごとの合計を返します:

```json
{
  "summary": [
    { "month": "2026-05", "category": "food", "total": 42000, "count": 12 },
    { "month": "2026-05", "category": "transport", "total": 8500, "count": 5 }
  ]
}
```

## PATCH 部分更新

ボディに含まれるフィールドのみが更新されます — 含まれないフィールドは現在の値を保持します:

```php
if (isset($body['amount'])) {
    if (!is_int($body['amount']) || $body['amount'] <= 0) {
        $errors[] = new ValidationError('amount', 'Amount must be a positive integer.');
    } else {
        $updates['amount'] = $body['amount'];
    }
}
// date、category、note も同様のパターン
```

## バリデーションパターンまとめ

| フィールド | チェック | 理由 |
|-------|-------|--------|
| `amount` | `is_int() && > 0` | 浮動小数点、ゼロ、負の値を拒否 |
| `date` | ラウンドトリップ `Y-m-d` パース | 正規化された ISO 8601 のみ |
| `category` | `in_array(strict: true)` | タイポとインジェクションを防止 |
| `limit` / `offset` | `max(1, min(100, $limit))` | DoS と SQL インジェクションを防止 |
