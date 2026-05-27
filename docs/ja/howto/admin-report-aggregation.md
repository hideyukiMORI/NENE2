# 管理レポート集計を追加する方法

日付範囲フィルター、グループ化、リミットのクランプを備えたダッシュボードスタイルの集計エンドポイントを構築します。

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
| `GET` | `/reports/summary` | 注文合計、収益、平均、完了数 |
| `GET` | `/reports/daily` | 日付ごとにグループ化した注文 |
| `GET` | `/reports/by-status` | ステータスごとにグループ化した注文 |
| `GET` | `/reports/top-items` | 収益上位 N 件のアイテム |

クエリパラメーター（全レポート共通）: `from=YYYY-MM-DD`、`to=YYYY-MM-DD`

## 日付範囲フィルター（安全なパラメータ化）

WHERE 句を動的に構築し、値をバインドパラメーターとして渡します — 文字列補間は絶対に行いません:

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

## 日付バリデーション（インジェクションへのガード）

クエリに到達する前に ISO-8601 でない日付を拒否します:

```php
private function isValidDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date; // 2026-13-01 を拒否する
}
```

`from > to` を拒否します:

```php
if ($from !== null && $to !== null && $from > $to) {
    $errors[] = new ValidationError('from', 'from must be before or equal to to', 'invalid');
}
```

## リミットのクランプ

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

**日次ブレークダウン**（SQLite の日付サブストリング）:
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

- すべてのクエリパラメーターは使用前に検証 — `from`/`to`/`limit` 経由の SQL インジェクションは 422 で拒否。
- アイテム名と顧客 ID はパラメータ化クエリで保存 — 特殊文字やインジェクション試行はリテラル文字列として扱われる。
- `COALESCE(SUM(...), 0)` で一致する行がない場合のサマリーで NULL を防止。
- リミットは `MAX_LIMIT` にクランプ — 巨大な `LIMIT` 値によるリソース枯渇を防止。
