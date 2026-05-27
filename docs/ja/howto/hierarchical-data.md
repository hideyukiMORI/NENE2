# 階層データ — 自己参照 FK + マテリアライズドパス

> **FT リファレンス**: FT171 (`NENE2-FT/hierarchylog`) — 自己参照 FK とマテリアライズドパスを使った階層カテゴリ（O(1) サブツリークエリ）。

単一の SQL テーブルで**自己参照外部キー**（`parent_id`）と**マテリアライズドパス**（`/1/3/7/`）を使って、カテゴリ（または任意の階層）のツリーを保存します。O(1) サブツリークエリが可能です。

---

## このパターンを使うべきとき

| このパターンを使う場合 | 代替を検討する場合 |
|---|---|
| 深さに制限がある（≤ 5〜10 レベル） | 頻繁な再親設定を伴う無制限の深さ |
| サブツリー読み取りが多い | 多くの移動を伴う書き込み重視のツリー |
| 単一データベースソリューションが好まれる | グラフ関係（複数の親） |

---

## スキーマ設計

```sql
CREATE TABLE categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    parent_id  INTEGER,                      -- NULL = ルートノード
    path       TEXT    NOT NULL UNIQUE,      -- マテリアライズドパス: "/1/", "/1/3/", "/1/3/7/"
    depth      INTEGER NOT NULL DEFAULT 0,  -- 0 = ルート
    created_at TEXT    NOT NULL,
    FOREIGN KEY (parent_id) REFERENCES categories(id)
);
```

### パス規約

- ルートノード: `/1/`（`/{id}/` と一致）
- ルートのレベル 1 の子: `/1/3/`
- レベル 2 の孫: `/1/3/7/`
- 常に `/` で始まり `/` で終わる。
- INSERT 後、`path = parentPath . newId . '/'` を計算して行を UPDATE する。

---

## コア操作

### 作成（パス計算付き）

```php
// 1. 一時的なプレースホルダーで INSERT
$id = $this->db->insert(
    'INSERT INTO categories (name, parent_id, path, depth, created_at) VALUES (?, ?, ?, ?, ?)',
    [$name, $parentId, '__tmp__', $depth, $now],
);
// 2. id がわかったのでパスを修正
$path = $parentPath . $id . '/';
$this->db->execute('UPDATE categories SET path = ? WHERE id = ?', [$path, $id]);
```

### サブツリークエリ（インデックス付きパスカラムで O(1)）

```php
// パス "/1/3/" を持つノードのすべての子孫
$rows = $this->db->fetchAll(
    "SELECT * FROM categories WHERE path LIKE ? AND id != ? ORDER BY path",
    [$root->path . '%', $rootId],
);
```

### 祖先

```php
// パス "/1/3/7/" → 祖先 id [1, 3]
$parts = array_filter(explode('/', $node->path));
$ancestorIds = array_filter(
    array_map('intval', $parts),
    fn(int $pid) => $pid !== $node->id,
);
```

### 移動（子孫へのカスケード）

```php
$oldPath = $node->path;
$newPath = $newParentPath . $id . '/';

// ノード自身を更新
$this->db->execute(
    'UPDATE categories SET parent_id = ?, path = ?, depth = ? WHERE id = ?',
    [$newParentId, $newPath, $newDepth, $id],
);

// すべての子孫にカスケード
foreach ($this->subtree($id) as $desc) {
    $updatedPath  = $newPath . substr($desc->path, strlen($oldPath));
    $updatedDepth = $desc->depth - $node->depth + $newDepth;
    $this->db->execute(
        'UPDATE categories SET path = ?, depth = ? WHERE id = ?',
        [$updatedPath, $updatedDepth, $desc->id],
    );
}
```

---

## バリデーションルール

| ルール | 実装 |
|---|---|
| 最大深さ | `if ($parent->depth >= MAX_DEPTH - 1) throw CategoryDepthException` |
| 循環（自己移動） | `if ($newParentId === $id) throw CategoryCircularException` |
| 循環（子孫） | `if (str_starts_with($newParent->path, $node->path)) throw CategoryCircularException` |
| 葉のみ削除 | `if ($children !== []) throw CategoryHasChildrenException` |
| 移動による深さオーバーフロー | 移動前に `$newDepth + maxSubtreeRelativeDepth >= MAX_DEPTH` をチェック |

---

## エンドポイント

| メソッド | パス | 説明 |
|---|---|---|
| `GET` | `/categories` | ルートカテゴリを一覧表示（子のために `?parent_id=N`） |
| `POST` | `/categories` | カテゴリを作成する |
| `GET` | `/categories/{id}` | 祖先チェーン付きで 1 件のカテゴリを取得する |
| `GET` | `/categories/{id}/subtree` | すべての子孫を取得する |
| `PUT` | `/categories/{id}` | カテゴリ名を変更する |
| `PATCH` | `/categories/{id}/move` | 新しい親に移動する（ルートは `parent_id: null`） |
| `DELETE` | `/categories/{id}` | 葉を削除する（子がある場合は 409 で拒否） |

---

## レスポンス形状

### カテゴリオブジェクト

```json
{
  "id": 7,
  "name": "PHP Frameworks",
  "parent_id": 3,
  "path": "/1/3/7/",
  "depth": 2,
  "created_at": "2026-01-01T00:00:00+00:00"
}
```

### 祖先付きの GET /categories/{id}

```json
{
  "data": { ... },
  "ancestors": [
    { "id": 1, "name": "Technology", "depth": 0, ... },
    { "id": 3, "name": "Programming", "depth": 1, ... }
  ]
}
```

---

## ドメイン層の構造

```
src/Category/
├── Category.php                    # readonly エンティティ
├── CategoryRepository.php          # ツリー操作（作成/一覧/サブツリー/祖先/移動/削除）
├── RouteRegistrar.php              # HTTP ハンドラーを Router に接続
├── CategoryNotFoundException.php
├── CategoryDepthException.php
├── CategoryCircularException.php
└── CategoryHasChildrenException.php
```

---

## ネストセット / クロージャーテーブルとのトレードオフ

| アプローチ | サブツリー読み取り | 挿入 | 移動 |
|---|---|---|---|
| **マテリアライズドパス**（このガイド） | 高速（`LIKE`） | O(1) | O(サブツリーサイズ) |
| クロージャーテーブル | 高速（結合） | O(祖先数) | O(サブツリー × 祖先) |
| ネストセット | 高速（`BETWEEN`） | O(テーブル) | O(テーブル) |

マテリアライズドパスは、移動が少ない深さ制限のあるツリーに最適です。祖先クエリをインデックスのみで行う必要があり、移動が頻繁な場合はクロージャーテーブルを使用してください。

---

## 参照

- [データベース対応エンドポイントを追加する](./add-database-endpoint.md)
- [ページネーションを追加する](./add-pagination.md)
- [ソフト削除](./soft-delete.md)
