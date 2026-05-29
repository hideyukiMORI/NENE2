---
title: "全文検索・オートコンプリート API の実装ガイド"
category: api-design
tags: [search, autocomplete, full-text-search, like-query]
difficulty: intermediate
related: [sqlite-fts5-search, use-fts5-search, dynamic-filter-query]
---

# 全文検索・オートコンプリート API の実装ガイド

## 概要

このガイドでは NENE2 を使って全文検索とオートコンプリートエンドポイントを実装する方法を説明します。
LIKE 検索による複数フィールド横断検索・関連度スコアリング・プレフィックス補完を REST API として提供します。

---

## DB スキーマ

```sql
CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL,
    price_cents INTEGER NOT NULL DEFAULT 0 CHECK (price_cents >= 0),
    created_at TEXT NOT NULL
);
```

---

## エンドポイント設計

| メソッド | パス | 説明 |
|---|---|---|
| GET | `/search` | 全文検索 |
| GET | `/autocomplete` | 名前プレフィックス補完 |

### クエリパラメータ

**GET /search**

| パラメータ | 必須 | デフォルト | 説明 |
|---|---|---|---|
| `q` | ✓ | — | 検索クエリ（2〜100 文字） |
| `category` | — | — | カテゴリフィルタ |
| `limit` | — | 10 | 最大 50 |
| `offset` | — | 0 | ページネーション |

**GET /autocomplete**

| パラメータ | 必須 | デフォルト | 説明 |
|---|---|---|---|
| `q` | ✓ | — | プレフィックス（2〜100 文字） |
| `limit` | — | 5 | 最大 10 |

---

## 実装

### SearchRepository

```php
class SearchRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db) {}

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function search(string $query, ?string $category, int $limit, int $offset): array
    {
        $lq = strtolower($query);
        $escaped = $this->escapeLike($lq);
        $pattern = '%' . $escaped . '%';
        $prefix  = $escaped . '%';

        $whereConditions = [
            "LOWER(name) LIKE ? ESCAPE '!'",
            "LOWER(description) LIKE ? ESCAPE '!'",
            "LOWER(category) LIKE ? ESCAPE '!'",
        ];
        $whereParams = [$pattern, $pattern, $pattern];
        $whereClause = 'WHERE (' . implode(' OR ', $whereConditions) . ')';

        if ($category !== null) {
            $whereClause .= ' AND LOWER(category) = ?';
            $whereParams[] = strtolower($category);
        }

        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS cnt FROM products ' . $whereClause,
            $whereParams
        ) ?? ['cnt' => 0];
        $total = (int) $row['cnt'];

        // Relevance: 0 = exact name, 1 = name starts with query, 2 = contains anywhere
        $selectParams = [$lq, $prefix, ...$whereParams, $limit, $offset];
        $items = $this->db->fetchAll(
            "SELECT id, name, description, category, price_cents, created_at,
                    CASE WHEN LOWER(name) = ? THEN 0
                         WHEN LOWER(name) LIKE ? ESCAPE '!' THEN 1
                         ELSE 2
                    END AS relevance
             FROM products " . $whereClause . "
             ORDER BY relevance ASC, id ASC
             LIMIT ? OFFSET ?",
            $selectParams
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return list<string> */
    public function autocomplete(string $prefix, int $limit): array
    {
        $escaped = $this->escapeLike(strtolower($prefix));
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT name FROM products WHERE LOWER(name) LIKE ? ESCAPE '!' ORDER BY name ASC LIMIT ?",
            [$escaped . '%', $limit]
        );
        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    private function escapeLike(string $value): string
    {
        // Use ! as escape char to avoid backslash confusion in SQL string literals
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
```

### RouteRegistrar（抜粋）

```php
public function register(Router $router): void
{
    $router->get('/search', $this->handleSearch(...));
    $router->get('/autocomplete', $this->handleAutocomplete(...));
}

private function handleSearch(ServerRequestInterface $request): ResponseInterface
{
    $params = $request->getQueryParams();
    $q      = isset($params['q']) ? trim((string) $params['q']) : '';
    $errors = $this->validateQuery($q);

    $limit  = $this->clamp((int) ($params['limit'] ?? 10), 1, 50);
    $offset = max(0, (int) ($params['offset'] ?? 0));
    $cat    = isset($params['category']) && trim((string) $params['category']) !== ''
                ? trim((string) $params['category']) : null;

    if ($errors !== []) {
        throw new ValidationException($errors);
    }

    $result = $this->repo->search($q, $cat, $limit, $offset);

    return $this->json->create([
        'query'    => $q,
        'category' => $cat,
        'total'    => $result['total'],
        'limit'    => $limit,
        'offset'   => $offset,
        'items'    => array_map($this->formatProduct(...), $result['items']),
    ]);
}
```

---

## 設計のポイント

### LIKE の特殊文字エスケープ

`%` と `_` は SQL の LIKE ワイルドカード。ユーザー入力をそのまま渡すと意図しない全件マッチや SQL インジェクションに近い挙動が起きる。

```php
// NG: ユーザーが "%_" を入力すると全件ヒット
$this->db->fetchAll('SELECT * FROM products WHERE name LIKE ?', ['%' . $query . '%']);

// OK: 特殊文字をエスケープする
private function escapeLike(string $value): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
}
// SQL: WHERE name LIKE ? ESCAPE '!'
```

エスケープ文字には `!` を使うことでバックスラッシュのエスケープ地獄（SQL/PHP 二重エスケープ）を避ける。

### 関連度スコアリング

LIKE 検索は全件に同じ重みを与えるが、`CASE WHEN` で簡易スコアを付ける:

| スコア | 条件 | 例 |
|---|---|---|
| 0 | 名前が完全一致 | "Apple iPhone 15" を "apple iphone 15" で検索 |
| 1 | 名前が前方一致 | "Apple" で始まる商品 |
| 2 | 名前・説明・カテゴリに含む | 説明文に "ergonomic" を含む |

```sql
CASE WHEN LOWER(name) = ? THEN 0
     WHEN LOWER(name) LIKE ? ESCAPE '!' THEN 1
     ELSE 2
END AS relevance
```

パラメータは `[$lq (完全一致用文字列), $prefix (前方一致パターン), ...WHERE句パラメータ, $limit, $offset]` の順で渡す。

### オートコンプリートは前方一致のみ

検索（`%query%`）とオートコンプリート（`query%`）は用途が異なる。
オートコンプリートで「含む」検索を返すと予測変換として不自然になる。

```php
// 前方一致のみ: "Apple" → ["Apple iPhone 15", "Apple Watch Series 9"]
$rows = $this->db->fetchAll(
    "SELECT DISTINCT name FROM products WHERE LOWER(name) LIKE ? ESCAPE '!' ORDER BY name ASC LIMIT ?",
    [$escaped . '%', $limit]
);
// "Green Apple Juice" は "Apple" で始まらないので含まれない
```

### limit クランプ

クライアントが任意の limit を送れると全件取得が可能になる。必ずサーバー側でクランプする。

```php
private function clamp(int $value, int $min, int $max): int
{
    return max($min, min($max, $value));
}

// 検索: max 50 / オートコンプリート: max 10
$limit = $this->clamp((int) ($params['limit'] ?? 10), 1, 50);
```

### SQLite vs MySQL/PostgreSQL の全文検索

| 手法 | 適用 | 特徴 |
|---|---|---|
| `LIKE '%query%'` | SQLite / MySQL / PgSQL | 小〜中規模。インデックス効かない（前方一致 `LIKE 'q%'` はインデックス有効） |
| SQLite FTS5 仮想テーブル | SQLite | 高速全文検索。トークナイザ設定・ランキング内蔵 |
| MySQL FULLTEXT | MySQL | `MATCH ... AGAINST` で AND/OR/フレーズ検索 |
| PostgreSQL `tsvector` | PgSQL | GIN インデックス・言語ステミング対応 |

プロトタイプや小規模なら LIKE で十分。数十万行以上では FTS に移行すること。

---

## レスポンス例

### GET /search?q=apple&category=Electronics

```json
{
  "query": "apple",
  "category": "Electronics",
  "total": 2,
  "limit": 10,
  "offset": 0,
  "items": [
    {
      "id": 1,
      "name": "Apple iPhone 15",
      "description": "Flagship smartphone by Apple",
      "category": "Electronics",
      "price_cents": 129900,
      "created_at": "2026-01-01T00:00:00Z"
    },
    {
      "id": 2,
      "name": "Apple Watch Series 9",
      "description": "Smartwatch with health tracking",
      "category": "Electronics",
      "price_cents": 49900,
      "created_at": "2026-01-01T00:00:00Z"
    }
  ]
}
```

### GET /autocomplete?q=Apple

```json
{
  "query": "Apple",
  "suggestions": [
    "Apple iPhone 15",
    "Apple Watch Series 9"
  ]
}
```

### GET /search?q=a （q が短すぎる → 422）

```json
{
  "status": 422,
  "errors": [
    { "field": "q", "message": "q must be at least 2 characters", "code": "too_short" }
  ]
}
```

---

## 参照実装

`../NENE2-FT/searchlog/` — FT157 フィールドトライアル（22 テスト）
