# ハウツー: 動的ソート・フィルターと ORDER BY インジェクション防止

> **FT リファレンス**: FT341 (`NENE2-FT/sortlog`) — 動的ソート/フィルター API: 許可リストによる SQL ORDER BY インジェクション防止、ステータスフィルター許可リスト、ReDoS 耐性 O(n) バリデーション、VULN-A〜VULN-L と ATK-01〜ATK-12 をカバーする 40 以上のテスト、全件 PASS。

このガイドでは、ソート可能でフィルタリング可能な一覧エンドポイントを安全に実装する方法を示します。`ORDER BY` は SQL でパラメーター化されたプレースホルダーを使えないため、カラム名と方向は補間前に厳格な許可リストに対して検証する必要があります。

## スキーマ

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'draft',
    created_at TEXT    NOT NULL
);
```

## エンドポイント

```
GET /articles?sort=created_at&order=desc&status=published&limit=20
```

### 有効なパラメーター

| パラメーター | 許可される値 | デフォルト |
|-------|---------------|---------|
| `sort` | `id`, `title`, `status`, `created_at` | `created_at` |
| `order` | `asc`, `desc` | `desc` |
| `status` | `draft`, `published`, `archived` | （全件） |
| `limit` | 1〜100 | 20 |

## レスポンス

```php
GET /articles?sort=title&order=asc&status=published
→ 200
{
  "data": [
    {"id": 2, "title": "Alpha", "status": "published", ...},
    {"id": 1, "title": "Beta",  "status": "published", ...}
  ],
  "total": 2,
  "sort": "title",
  "order": "asc"
}
```

## 許可リストバリデーション — 唯一の安全なパターン

`ORDER BY` 句はバインドパラメーターを**使えません**。カラム名は SQL に直接補間する必要があります。これにより許可リストバリデーションが必須になります。

```php
private const SORT_COLUMNS = ['id', 'title', 'status', 'created_at'];
private const SORT_ORDERS  = ['asc', 'desc'];
private const STATUSES     = ['draft', 'published', 'archived'];

public function parseSort(string $sort, string $order): array
{
    // 許可リストへの完全一致 — O(n)、大文字小文字を区別、正規表現なし
    if (!in_array($sort, self::SORT_COLUMNS, true)) {
        throw new ValidationException("Invalid sort column: {$sort}");
    }
    if (!in_array($order, self::SORT_ORDERS, true)) {
        throw new ValidationException("Invalid sort direction: {$order}");
    }
    return [$sort, $order];
}

public function parseStatus(?string $status): ?string
{
    if ($status === null) {
        return null;  // フィルターなし
    }
    if (!in_array($status, self::STATUSES, true)) {
        throw new ValidationException("Invalid status: {$status}");
    }
    return $status;
}
```

**正規表現ではなく `in_array()` を使う理由:**
- `in_array($v, $list, true)` は O(n) — ReDoS 耐性あり
- 攻撃者が制御する 50 文字のペイロードに対する `/^[a-z_]+$/` は壊滅的なバックトラッキングを引き起こす可能性がある
- 第 3 引数の strict（`true`）により型安全な比較が有効になる

### 大文字小文字の区別

許可リストは意図的に大文字小文字を区別します:

```php
GET /articles?sort=ID       → 422  // 'ID' は許可リストにない
GET /articles?sort=TITLE    → 422
GET /articles?sort=Created_At → 422
GET /articles?sort=created_at → 200  ✅ 完全一致
```

## クエリ構築

```php
$sql = 'SELECT * FROM articles';

if ($status !== null) {
    // status はパラメーター化されたプレースホルダーを使用（安全）
    $sql .= ' WHERE status = ?';
    $params[] = $status;
}

// ソートカラムと方向は許可リストから — 補間しても安全
$sql .= " ORDER BY {$sort} {$order}";
$sql .= ' LIMIT ?';
$params[] = $limit;
```

`ORDER BY` は補間された許可リスト値を使用。`WHERE` 句の値は常に `?` プレースホルダーを使用します。

## 拒否されるペイロード

### インジェクションパターン → 422

```php
// sort での SQL インジェクション
?sort='; DROP TABLE articles--             → 422
?sort=id UNION SELECT 1,2,3,4,5           → 422
?sort=(SELECT name FROM sqlite_master)    → 422
?sort=CASE WHEN 1=1 THEN id ELSE title END → 422
?sort=created_at--                        → 422  // コメント
?sort=created_at%00                       → 422  // null バイト
?sort=1                                   → 422  // カラムインデックス（許可リストにない）

// direction インジェクション
?order=asc; UNION SELECT 1,2,3--          → 422
?order=DESC;                              → 422

// status フィルターインジェクション
?status=' OR '1'='1                       → 422
?status=draft UNION SELECT 1,2--          → 422
?status=1                                 → 422  // 正確なステータス名でなければならない
?status=TRUE                              → 422

// ホワイトスペースバイパス
?sort=created_at%09                       → 422  // TAB
?sort= created_at                         → 422  // 先頭スペース

// 配列インジェクション（PSR-7）
?sort[]=created_at                        → 422  // 文字列ではなく配列
```

### limit インジェクション → 422

```php
?limit=999999           → 422  // MAX_LIMIT=100 を超過
?limit=9999999999999999999999  → 422  // オーバーフロー（strlen > 18）
?limit=-1               → 422  // 負の値
?limit=10.5             → 422  // 浮動小数点
```

### 有効なリクエスト → 200

```php
GET /articles                                  → 200  // デフォルト値
GET /articles?sort=title&order=asc             → 200
GET /articles?sort=id&order=desc&status=draft  → 200
GET /articles?limit=50                         → 200
```

## タイミング安全性

すべての拒否は即座（<100ms）に行われます。許可リストチェックは `in_array()` を使用し、最初の不一致で短絡します — 正規表現のバックトラッキングなし:

```php
// ReDoS ペイロード: "aaaa...a!" （50 個の a + '!'）
// in_array("aaaa...a!", ['id','title','status','created_at'], true) → 即座に false
```

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| `?sort=` を直接補間: `ORDER BY $sort` | SQL インジェクション — 攻撃者が `ORDER BY` 句を完全にコントロールできる |
| 正規表現 `/^[a-z_]+$/` のみでバリデーション | 50 文字以上のペイロードで ReDoS。`password` のような未知のカラム名を許可してしまう |
| 大文字小文字を区別しない比較（`strcasecmp`） | `ORDER BY CREATED_AT` は有効な SQL だが大文字小文字を区別するテストをバイパスする |
| `ORDER BY $sort` をバインド値としてパラメーター化 | ほとんどの DB ドライバーはリテラルとして扱うか、エラーをスローする |
| `sort` のみを許可リスト化し、`order` 方向は許可リスト化しない | `order=asc; UNION SELECT ...` でカラムチェックをバイパスできる |
| PSR-7 パース後に `sort[]` 配列を信頼する | 配列インジェクションで `implode(', ', $sort)` が複数カラムの ORDER BY を生成する |
| `status` フィルターの許可リストを省略 | `status=admin' OR '1'='1` が `WHERE status = 'admin' OR '1'='1'` になる |
