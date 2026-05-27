# ハウツー: 管理レポート集計を追加する

日付範囲フィルター、グループ化、制限クランプを備えたダッシュボードスタイルの集計エンドポイントを構築します。

## スキーマ

```sql
CREATE TABLE orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id TEXT NOT NULL, item_name TEXT NOT NULL,
    amount INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','completed','refunded','cancelled')),
    created_at TEXT NOT NULL
);
```

## ルート

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/orders` | 注文を挿入する |
| `GET` | `/reports/summary` | 総注文数、売上、平均、完了数 |
| `GET` | `/reports/daily` | 日付別グループの注文 |
| `GET` | `/reports/by-status` | ステータス別グループの注文 |
| `GET` | `/reports/top-items` | 売上上位 N アイテム |

クエリパラメーター（全レポート）: `from=YYYY-MM-DD`、`to=YYYY-MM-DD`

## 日付範囲フィルター（安全なパラメータ化）

WHERE 句を動的に構築し、値をバインドパラメーターとして渡す — 補間はしない:

```php
private function dateFilter(?string $from, ?string $to): array
{
    $conditions = [];
    $params     = [];
    if ($from !== null) { $conditions[] = 'created_at >= ?'; $params[] = $from; }
    if ($to !== null)   { $conditions[] = 'created_at <= ?'; $params[] = $to; }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
    return [$where, $params];
}
```

## 日付バリデーション（インジェクション防止）

非 ISO-8601 日付がクエリに到達する前に拒否します:

```php
private function isValidDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date; // 2026-13-01 を拒否
}
```

`from > to` を拒否します:

```php
if ($from !== null && $to !== null && $from > $to) {
    $errors[] = new ValidationError('from', 'from must be before or equal to to', 'invalid');
}
```

## 制限クランプ

```php
private const int MAX_LIMIT = 100;

$limit = min((int) $q['limit'], self::MAX_LIMIT);
if ($limit <= 0) { /* 422 */ }
```

## 集計クエリ

**サマリー**:
```sql
SELECT COUNT(*) AS total_orders,
       COALESCE(SUM(amount), 0) AS total_revenue,
       COALESCE(AVG(amount), 0) AS avg_order_value,
       COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_orders
FROM orders {where}
```

**日別内訳**（SQLite 日付文字列のサブストリング）:
```sql
SELECT substr(created_at, 1, 10) AS date, COUNT(*) AS order_count, SUM(amount) AS revenue
FROM orders {where}
GROUP BY date ORDER BY date ASC
```

**上位アイテム**:
```sql
SELECT item_name, COUNT(*) AS order_count, SUM(amount) AS revenue
FROM orders {where}
GROUP BY item_name ORDER BY revenue DESC LIMIT ?
```

## セキュリティ注意事項

- すべてのクエリパラメーターは使用前に検証済み — `from`/`to`/`limit` による SQL インジェクションは 422 で拒否されます。
- アイテム名と顧客 ID はパラメータ化クエリで保存 — 特殊文字とインジェクション試行はリテラル文字列として扱われます。
- `COALESCE(SUM(...), 0)` で一致する行がない場合のサマリーの NULL を防止します。
- 制限は `MAX_LIMIT` にクランプ — 巨大な `LIMIT` 値によるリソース枯渇を防止します。
