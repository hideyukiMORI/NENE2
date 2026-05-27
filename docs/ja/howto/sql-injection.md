# SQL インジェクション防御

NENE2 のデータベースメソッド（`execute`、`insert`、`fetchOne`、`fetchAll`）は内部で PDO プリペアドステートメントを使用します。`$parameters` 配列に渡されたすべての値は PDO パラメーターとしてバインドされます — SQL 文字列に補間されることはありません。

## デフォルトで安全: 値パラメーター

```php
// すべての値は PDO バインディングを経由 — コンテンツに関わらずインジェクション安全
$product = $this->db->fetchOne(
    'SELECT * FROM products WHERE id = ?',
    [$userId],
);

// LIKE 検索 — SQL リテラルのワイルドカード、値は別個にバインド
$rows = $this->db->fetchAll(
    "SELECT * FROM products WHERE name LIKE '%' || ? || '%'",
    [$searchQuery],
);
```

クラシックなペイロード（`' OR '1'='1`、`'; DROP TABLE products; --`、`UNION SELECT ...`）は PDO が SQL に補間しないためリテラル検索文字列になります。

## ORDER BY の落とし穴 — ホワイトリストが必須

**PDO はカラム名や SQL 構造要素をパラメーター化できません。** `ORDER BY ?` は動作しません — カラム参照ではなくリテラル文字列値をバインドします。

開発者がユーザー入力を直接 `ORDER BY` に入れると、インジェクションベクターになります:

```php
// 危険 — 絶対にやらない
$sort = QueryStringParser::string($request, 'sort') ?? 'id';
$rows = $this->db->fetchAll("SELECT * FROM products ORDER BY {$sort} ASC");
// ?sort=id;+DROP+TABLE+products;+-- は DROP を実行する
```

**カラム名を補間する前に常に明示的なホワイトリストに対して検証してください:**

```php
private const array ALLOWED_SORT_FIELDS = ['id', 'name', 'price', 'created_at'];

public function list(string $sortField, string $sortDir): array
{
    if (!in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
        throw new InvalidSortFieldException("Invalid sort field: {$sortField}");
    }

    // ASC または DESC のみ — 正規化して生のユーザー入力を補間しない
    $dir  = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
    $rows = $this->db->fetchAll(
        "SELECT * FROM products ORDER BY {$sortField} {$dir}",
    );

    return $rows;
}
```

同じ原則はすべての SQL 構造要素に適用されます: テーブル名、`GROUP BY` のカラム名、`HAVING`、`INSERT INTO ... (col1, col2)` — これらはいずれも PDO パラメーターとしてバインドできません。補間する前にホワイトリスト検証してください。

## 可変長の IN 句

PDO は可変長リストを直接バインドすることをサポートしていません。プレースホルダーリストを明示的に構築してください:

```php
$ids          = [1, 2, 3];
$placeholders = implode(', ', array_fill(0, count($ids), '?'));
$rows         = $this->db->fetchAll(
    "SELECT * FROM products WHERE id IN ({$placeholders})",
    $ids,
);
```

## サマリー

| 入力タイプ | 安全なメソッド |
|---|---|
| フィルター値（`WHERE col = ?`） | `$parameters` の `?` プレースホルダー |
| LIKE 値 | `'%' \|\| ? \|\| '%'` — `$parameters` に値 |
| ORDER BY カラム | ホワイトリスト `in_array` + パス後にのみ補間 |
| ORDER 方向 | リテラル `'ASC'` または `'DESC'` に正規化 |
| IN リスト | `count()` から `?` プレースホルダーを構築、配列をパラメーターとして展開 |
| テーブル/カラム名 | ホワイトリストのみ — ユーザー入力から受け入れない |
