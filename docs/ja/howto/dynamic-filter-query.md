# ハウツー: 動的フィルタークエリ（動的 WHERE 句）

> **関連シナリオ**: DX シナリオ 03、18、22、25、29、30、33、37、38、41、47、48 — 50 の DX シナリオで最も頻繁に引用される不足していたハウツー。

多くの一覧エンドポイントは SQL 条件に変換されるオプションのクエリパラメーターを受け取ります。
主な課題: パラメーターが不在（`null`）の場合、条件は**完全にスキップ**する必要があります — SQL で `NULL` と比較するのではなく。

このガイドでは NENE2 ハウツー全体で使われる標準パターンを示します。

---

## コアパターン: `$conditions` 配列 + `implode`

```php
public function search(
    ?string $status,
    ?int    $categoryId,
    ?string $keyword,
): array {
    $conditions = ['deleted_at IS NULL'];   // 必須条件 — 常に含める
    $bindings   = [];

    if ($status !== null) {
        $conditions[] = 'status = ?';
        $bindings[]   = $status;
    }

    if ($categoryId !== null) {
        $conditions[] = 'category_id = ?';
        $bindings[]   = $categoryId;
    }

    if ($keyword !== null && $keyword !== '') {
        $conditions[] = '(title LIKE ? OR description LIKE ?)';
        $bindings[]   = "%{$keyword}%";
        $bindings[]   = "%{$keyword}%";
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    return $this->db->fetchAll(
        "SELECT * FROM products {$where} ORDER BY created_at DESC",
        $bindings,
    );
}
```

**なぜこれが機能するか**:
- `$conditions` には常に少なくとも 1 つの要素（必須条件）があるため、`implode(' AND ', $conditions)` が空文字列を生成することはありません。
- 各オプションブロックは SQL フラグメントとそのバインディング値の両方を追加します — 同期が保たれます。
- すべてのオプションパラメーターが `null` の場合、クエリは `WHERE deleted_at IS NULL` に減少します。

---

## アンチパターン: `WHERE 1=1`

一般的な代替は `WHERE 1=1` を種条件として、常に `AND` を追加します:

```php
// 機能するが、より不明確:
$where = 'WHERE 1=1';
if ($status !== null) {
    $where    .= ' AND status = ?';
    $bindings[] = $status;
}
```

これも機能します。`$conditions` 配列アプローチは SQL フラグメントとそのバインディングをクリーンに分離し、各条件を個別にテストしやすいため好ましいです。

---

## 範囲条件: min/max フィルター

価格範囲、日付範囲、および類似の `>=` / `<=` フィルター:

```php
public function searchProperties(
    ?int    $priceMin,
    ?int    $priceMax,
    ?int    $bedroomsMin,
    ?string $dateFrom,
    ?string $dateTo,
): array {
    $conditions = ['status = ?'];
    $bindings   = ['available'];

    if ($priceMin !== null) {
        $conditions[] = 'price_yen >= ?';
        $bindings[]   = $priceMin;
    }

    if ($priceMax !== null) {
        $conditions[] = 'price_yen <= ?';
        $bindings[]   = $priceMax;
    }

    if ($bedroomsMin !== null) {
        $conditions[] = 'bedrooms >= ?';
        $bindings[]   = $bedroomsMin;
    }

    if ($dateFrom !== null) {
        $conditions[] = 'available_from >= ?';
        $bindings[]   = $dateFrom;
    }

    if ($dateTo !== null) {
        $conditions[] = 'available_from <= ?';
        $bindings[]   = $dateTo;
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    return $this->db->fetchAll(
        "SELECT * FROM properties {$where} ORDER BY price_yen ASC",
        $bindings,
    );
}
```

`BETWEEN` ではなく別々の `min` と `max` 条件を使います — クライアントが片方の境界のみを提供できます（例: 「価格 500 万以下、下限なし」）。

---

## Enum / 許可リストフィルター

パラメーター値が固定セットから来る必要がある場合は、`$conditions` に追加する前にバリデーションします:

```php
private const VALID_STATUSES = ['draft', 'published', 'archived'];

public function listByStatus(?string $status): array
{
    $conditions = ['deleted_at IS NULL'];
    $bindings   = [];

    if ($status !== null) {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }
        $conditions[] = 'status = ?';
        $bindings[]   = $status;
    }

    // ...
}
```

安全に見えても `$status` を SQL 文字列に直接補間しては**いけません**。
常にバインドパラメーター（`?`）を使用し、PDO にクォーティングを任せます。

---

## IN 句: 複数値フィルター

クライアントが複数の値を渡せる場合（例: `?category_ids[]=1&category_ids[]=3`）:

```php
public function filterByCategories(array $categoryIds): array
{
    if ($categoryIds === []) {
        return $this->findAll();   // フィルターなし — すべてを返す
    }

    $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));

    return $this->db->fetchAll(
        "SELECT * FROM products WHERE category_id IN ({$placeholders}) ORDER BY created_at DESC",
        $categoryIds,
    );
}
```

`array_fill(0, count($ids), '?')` が正しい数の `?` プレースホルダーを生成します。
`IN (1,2,3)` 文字列を構築するために `implode(',', $categoryIds)` を使わないでください — SQL インジェクションです。

AND セマンティクス（**すべての**指定タグに一致するアイテム）については、[`multi-value-tag-filter.md`](multi-value-tag-filter.md) を参照してください。

---

## 安全な ORDER BY: 許可リスト補間

`ORDER BY` カラム名はバインドパラメーターを**使用できません** — 補間が必要です。
常に許可リストに対して検証してください:

```php
private const SORT_COLUMNS  = ['name', 'price_yen', 'created_at'];
private const SORT_DIRECTION = ['asc', 'desc'];

public function list(?string $sortBy, ?string $order): array
{
    $col = in_array($sortBy, self::SORT_COLUMNS, true)  ? $sortBy : 'created_at';
    $dir = in_array($order,  self::SORT_DIRECTION, true) ? $order  : 'desc';

    $conditions = ['deleted_at IS NULL'];

    $where = 'WHERE ' . implode(' AND ', $conditions);

    return $this->db->fetchAll(
        "SELECT * FROM products {$where} ORDER BY {$col} {$dir}",
        [],
    );
}
```

ORDER BY インジェクション防止の詳細については [`dynamic-sort-order-injection.md`](dynamic-sort-order-injection.md) を参照してください。

---

## フィルターとページネーションの組み合わせ

一般的なパターン — 動的フィルター + カーソルまたはオフセットページネーション:

```php
public function paginatedSearch(
    ?string $status,
    ?string $keyword,
    int     $limit,
    int     $offset,
): array {
    $conditions = ['deleted_at IS NULL'];
    $bindings   = [];

    if ($status !== null) {
        $conditions[] = 'status = ?';
        $bindings[]   = $status;
    }

    if ($keyword !== null && $keyword !== '') {
        $conditions[] = 'title LIKE ?';
        $bindings[]   = "%{$keyword}%";
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    // COUNT クエリは同じ WHERE を再利用
    $total = (int) ($this->db->fetchOne(
        "SELECT COUNT(*) AS cnt FROM products {$where}",
        $bindings,
    )['cnt'] ?? 0);

    // データクエリは LIMIT/OFFSET を追加 — COUNT の前に $bindings に追加しないこと
    $rows = $this->db->fetchAll(
        "SELECT * FROM products {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?",
        [...$bindings, $limit, $offset],
    );

    return ['total' => $total, 'items' => $rows];
}
```

まずフィルター条件の `$bindings` を構築し、`COUNT` クエリとデータクエリの両方にスプレッドします。`$limit` と `$offset` はデータクエリにのみ追加します。

---

## オプションクエリパラメーターのパース

リクエストから `null` セーフな型付き値を取得するために `QueryStringParser` ヘルパーを使用してください:

```php
use Nene2\Http\QueryStringParser;

$status     = QueryStringParser::string($request, 'status');     // ?string
$priceMin   = QueryStringParser::int($request, 'price_min');     // ?int
$priceMax   = QueryStringParser::int($request, 'price_max');     // ?int
$keyword    = QueryStringParser::string($request, 'q');          // ?string
$sortBy     = QueryStringParser::string($request, 'sort');       // ?string
$categoryId = QueryStringParser::int($request, 'category_id');   // ?int
```

すべてのヘルパーはパラメーターが不在またはターゲット型にパースできない場合に `null` を返します。
これらの `null` 可能な値をリポジトリメソッドに直接渡してください — メソッドは値が `null` の場合に条件をスキップします。

---

## よくある間違い

| 間違い | 問題 | 修正 |
|---------|---------|-----|
| `null` バインディングで `WHERE status = ?` | SQLite は `status = NULL` を評価 → 常に false（`IS NULL` を使うべき） | 値が `null` の場合は条件をスキップ。NULL 行が明示的に必要な場合のみ `IS NULL` を使用 |
| 必須条件なしの `WHERE 1=1` | すべてのオプションパラメーターが不在でテナント/オーナーフィルターもない場合、全行が漏洩する | 常に少なくとも 1 つの必須条件を含める（テナント、オーナー、deleted_at） |
| `$status` を直接補間 | SQL インジェクション | 常に `?` バインドパラメーターを使用 |
| `IN (implode(',', $ids))` | SQL インジェクション | `array_fill` + `?` プレースホルダーを使用 |
| `COUNT(*)` の前に `LIMIT`/`OFFSET` を `$bindings` に追加 | COUNT が間違った結果を取得する | まずフィルター `$bindings` を構築。COUNT にスプレッドし、データクエリに LIMIT/OFFSET を追加 |

---

## 関連ハウツー

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — N:M タグフィルターの AND / OR セマンティクス（`HAVING COUNT(DISTINCT)`）
- [`dynamic-sort-order-injection.md`](dynamic-sort-order-injection.md) — 許可リストを使った安全な ORDER BY
- [`add-pagination.md`](add-pagination.md) — オフセット / カーソルページネーションとの組み合わせ
- [`contact-management.md`](contact-management.md) — LIKE + EXISTS フィルターの完全な例
