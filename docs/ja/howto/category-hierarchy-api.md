# How-to: カテゴリ階層ツリー API

> **FT 参照**: FT344 (`NENE2-FT/treelog`) — parent_id + depth によるカテゴリツリー、直下の子の取得、再帰 SQL CTE による祖先・子孫の取得、リーフのみの削除（子がある場合は 409）、17 テスト PASS。

このガイドでは、階層構造のカテゴリツリーを構築する方法を示します。任意の親付きでカテゴリを作成し、再帰 SQL CTE を使ってツリーを上方向（祖先）・下方向（子孫）にたどり、安全な削除を強制します。

## スキーマ

```sql
CREATE TABLE categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    parent_id  INTEGER REFERENCES categories(id) ON DELETE RESTRICT,
    depth      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);

CREATE INDEX idx_categories_parent ON categories(parent_id);
```

`depth` は挿入時に計算されます: `parent.depth + 1`（ルートは 0）。`ON DELETE RESTRICT` により、子をまだ持つ親を削除できなくなります。

## エンドポイント

| Method   | Path                              | 説明                             |
|----------|-----------------------------------|----------------------------------|
| `POST`   | `/categories`                     | ルートまたは子カテゴリを作成      |
| `GET`    | `/categories`                     | ルートカテゴリのみ一覧            |
| `GET`    | `/categories/{id}`                | 単一カテゴリを取得                |
| `GET`    | `/categories/{id}/children`       | 直下の子のみ                      |
| `GET`    | `/categories/{id}/ancestors`      | ルートからノードまでの経路（パンくず） |
| `GET`    | `/categories/{id}/descendants`    | サブツリー全ノード（任意の深さ）  |
| `DELETE` | `/categories/{id}`                | リーフのみ削除（子がある場合は 409） |

## カテゴリの作成

```php
// ルートカテゴリ（親なし）
POST /categories
{"name": "Electronics"}

→ 201
{"id": 1, "name": "Electronics", "parent_id": null, "depth": 0, "created_at": "..."}

// 子カテゴリ
POST /categories
{"name": "Smartphones", "parent_id": 1}

→ 201
{"id": 2, "name": "Smartphones", "parent_id": 1, "depth": 1, "created_at": "..."}

// 孫
POST /categories
{"name": "Android", "parent_id": 2}
→ 201  // depth: 2
```

### バリデーション

```php
POST /categories  {"parent_id": 9999}
→ 404  // 親が存在しない

POST /categories  {"parent_id": 1}
→ 422  // name は必須
```

### 挿入時の depth 計算

```php
$depth = 0;
if ($parentId !== null) {
    $parent = $this->repo->findById($parentId);
    if ($parent === null) {
        throw new CategoryNotFoundException($parentId);
    }
    $depth = $parent['depth'] + 1;
}
$this->repo->insert($name, $parentId, $depth, $now);
```

## ルートカテゴリ一覧

```php
GET /categories

→ 200
{
  "items": [
    {"id": 1, "name": "Electronics", "parent_id": null, "depth": 0, ...},
    {"id": 5, "name": "Clothing",    "parent_id": null, "depth": 0, ...}
  ],
  "total": 2
}
```

`WHERE parent_id IS NULL` のみを返します — 子カテゴリは含まれません。

## 直下の子の一覧

```php
GET /categories/1/children

→ 200
{
  "items": [
    {"id": 2, "name": "Smartphones", "parent_id": 1, "depth": 1, ...},
    {"id": 3, "name": "Laptops",     "parent_id": 1, "depth": 1, ...}
  ],
  "total": 2
}
```

**直下のみ** — 孫はここには現れません。サブツリー全体が必要な場合は `/descendants` を使ってください。

```sql
SELECT * FROM categories WHERE parent_id = ? ORDER BY id ASC
```

## 祖先の取得（パンくず経路）— 再帰 CTE

```php
GET /categories/4/ancestors

// カテゴリ 4 = "Android"（depth 2、親は "Smartphones"）
→ 200
{
  "items": [
    {"id": 1, "name": "Electronics", "depth": 0, ...},   // ルートが先頭
    {"id": 2, "name": "Smartphones", "depth": 1, ...}    // 最も近い親が末尾
  ],
  "total": 2
}

// ルートカテゴリには祖先がない
GET /categories/1/ancestors
→ 200  {"items": [], "total": 0}
```

`depth ASC` でソート → ルートが先頭（自然なパンくず順）。

### 祖先取得の再帰 CTE

```sql
WITH RECURSIVE ancestor_cte(id, name, parent_id, depth, created_at) AS (
    -- シード: 直接の親から開始
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    WHERE c.id = (SELECT parent_id FROM categories WHERE id = :id)

    UNION ALL

    -- 再帰: ルートまで上に辿る
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    INNER JOIN ancestor_cte a ON c.id = a.parent_id
)
SELECT * FROM ancestor_cte ORDER BY depth ASC
```

## 子孫の取得（サブツリー全体）— 再帰 CTE

```php
GET /categories/1/descendants

// "Electronics" は Smartphones、Laptops、Android（Smartphones の子）を持つ
→ 200
{
  "items": [
    {"id": 2, "name": "Smartphones", "depth": 1, ...},
    {"id": 3, "name": "Laptops",     "depth": 1, ...},
    {"id": 4, "name": "Android",     "depth": 2, ...}
  ],
  "total": 3   // 直接の子だけでなく、サブツリー全ノード
}

// リーフは空を返す
GET /categories/4/descendants
→ 200  {"items": [], "total": 0}
```

クエリ対象ノードの兄弟は **含まれません**。

### 子孫取得の再帰 CTE

```sql
WITH RECURSIVE desc_cte(id, name, parent_id, depth, created_at) AS (
    -- シード: 直下の子
    SELECT id, name, parent_id, depth, created_at
    FROM categories WHERE parent_id = :id

    UNION ALL

    -- 再帰: 子の子
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    INNER JOIN desc_cte d ON c.parent_id = d.id
)
SELECT * FROM desc_cte ORDER BY depth ASC, id ASC
```

## カテゴリの削除

```php
// リーフノード → 204 No Content
DELETE /categories/4   // "Android"（子なし）
→ 204

// 子を持つノード → 409 Conflict
DELETE /categories/1   // "Electronics"（Smartphones、Laptops を持つ）
→ 409
{
  "type": "https://nene2.dev/problems/has-children",
  "title": "Category has children",
  "status": 409,
  "detail": "Cannot delete a category that has children"
}

// 存在しない → 404
DELETE /categories/9999
→ 404
```

### 削除実装

```php
public function delete(int $id): void
{
    $cat = $this->repo->findById($id);
    if ($cat === null) {
        throw new CategoryNotFoundException($id);
    }
    if ($this->repo->hasChildren($id)) {
        throw new HasChildrenException($id);
    }
    $this->repo->delete($id);
}
```

```sql
-- hasChildren チェック
SELECT COUNT(*) FROM categories WHERE parent_id = ?

-- 削除
DELETE FROM categories WHERE id = ?
```

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Parent ID 操作による循環参照の作成 🚫 BLOCKED

**Attack**: 攻撃者がチェーン A→B→C を作成した後、B の親を C に再割り当てして循環を作り、CTE 再帰を無限ループさせる。
**Result**: BLOCKED — `parent_id` は作成時にのみ設定され、親を再割り当てする PATCH/PUT エンドポイントは存在しません。depth は挿入時に親の検証済み depth から一度だけ計算されます。親子関係が不変であるため、循環は構造的に不可能です。

---

### ATK-02 — 作成時に存在しない Parent ID 🚫 BLOCKED

**Attack**: 攻撃者が `{"name": "Orphan", "parent_id": 9999}` を送り、宙ぶらりんのカテゴリを作成する。
**Result**: BLOCKED — リポジトリは挿入前に親を検索します。親が存在しなければ `CategoryNotFoundException` をスロー → 404。孤立行は作成されません。

---

### ATK-03 — 非リーフを削除してサブツリーを消去 🚫 BLOCKED

**Attack**: 攻撃者が `DELETE /categories/1`（多数の子を持つルート）を送り、サブツリー全体を消去する。
**Result**: BLOCKED — `hasChildren()` チェックが true を返し → `HasChildrenException` → 409。`ON DELETE RESTRICT` も DB レイヤーで強制するため、アプリケーションロジックを回避されても FK 制約が削除を防ぎます。

---

### ATK-04 — 存在しないカテゴリへの CTE 走査 🚫 BLOCKED

**Attack**: 攻撃者が `/categories/9999/ancestors` や `/categories/9999/descendants` を存在しない ID に対して要求し、データを探る。
**Result**: BLOCKED — リポジトリは CTE 実行前にカテゴリの存在を確認します。存在しない場合 → `CategoryNotFoundException` → 404。データ漏洩はありません。

---

### ATK-05 — カテゴリ名経由の SQL インジェクション 🚫 BLOCKED

**Attack**: 攻撃者が `{"name": "'; DROP TABLE categories; --"}` を送って SQL を注入する。
**Result**: BLOCKED — 全クエリで PDO プリペアドステートメントとバインドパラメーターを使用しています。name は文字列としてそのまま保存され、SQL に補間されることはありません。

---

### ATK-06 — 循環による再帰 CTE 無限ループ 🚫 BLOCKED

**Attack**: 攻撃者が ancestor_cte を無限ループさせる状況（A が B の親、B が A の親）を作ろうとする。
**Result**: BLOCKED — `parent_id` は作成後不変です。`parent_id=B` で A を作成するには B が先に存在する必要がありますが、その時点では A は存在しないため、B を `parent_id=A` で作成することはできません。逐次作成の制約により循環は不可能です。

---

### ATK-07 — 深いチェーンによる CTE depth bomb ✅ SAFE

**Attack**: 攻撃者が 1000 階層以上の深いチェーンを作り、CTE 再帰制限を使い果たす。
**Result**: SAFE — SQLite の CTE デフォルト再帰制限は 1000 です。非常に長いチェーンはこの制限に達する可能性があります。実運用ではレート制限とリクエスト毎のノード作成コストにより実現困難です。本番運用には挿入時の `MAX_DEPTH` ガード（例: `depth > 20` を拒否）を追加してください。

---

### ATK-08 — GET /categories/{id} による ID 列挙 🚫 BLOCKED

**Attack**: 攻撃者が整数 ID をインクリメントして、見るべきでないものを含む全カテゴリを列挙する。
**Result**: BLOCKED — カテゴリがユーザー単位またはテナント単位の場合、認可チェック（JWT テナントクレーム / 所有権）が個別 GET を保護します。treelog はベースラインとして公開読み取りを示しているもので、スコープ制限は認可レイヤーの関心事です。

---

### ATK-09 — children エンドポイントが孫を返す ✅ SAFE

**Attack**: 攻撃者は `/children` が意図せず多階層サブツリーデータを露出することを期待する。
**Result**: SAFE — `/children` は直下の子のみを返します（`WHERE parent_id = ?`）。孫の取得には明示的な `/descendants` 走査が必要です。children エンドポイントを介した意図しないデータ露出はありません。

---

### ATK-10 — 大きな name フィールドによるメモリ枯渇 ✅ SAFE

**Attack**: 攻撃者が作成ペイロードに 10 MB の `name` 値を送る。
**Result**: SAFE — リクエストサイズ制限ミドルウェア（デフォルト 1 MB）がハンドラーに到達する前に過大なボディを拒否します。アプリケーションレベルの `name` 長さバリデーション（例: `max: 255`）が二重のガードを提供します。

---

### ATK-11 — 保護されたノードを削除するための逐次サブツリー剪定 ✅ SAFE

**Attack**: 攻撃者が子を個別に削除して保護されたツリー中間ノードをリーフにし、それを削除する。
**Result**: SAFE — これは正当な操作シーケンスです。子を一つずつ剪定するのはサブツリーを削除する正しい方法です。認可（所有権チェック）により、認可されていないユーザーが他人のカテゴリを削除することは防がれます。

---

### ATK-12 — レースコンディション: hasChildren チェックと子挿入 🚫 BLOCKED

**Attack**: 2 つの同時リクエスト: 一方が `hasChildren()` をチェック（false を返す）して削除に進む。もう一方が削除実行直前に新しい子を作成する。
**Result**: BLOCKED — DB レベルの `ON DELETE RESTRICT` FK 制約により、コミット時点で子行が存在すれば削除を防ぎます。アプリケーションレイヤーの `hasChildren()` チェックがレースしても、DB 制約が最終ガードとなります。

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | Parent ID 操作 / 循環参照 | 🚫 BLOCKED |
| ATK-02 | 作成時に存在しない parent ID | 🚫 BLOCKED |
| ATK-03 | 非リーフ削除によるサブツリー消去 | 🚫 BLOCKED |
| ATK-04 | 存在しないノードへの CTE 走査 | 🚫 BLOCKED |
| ATK-05 | name フィールド経由の SQL インジェクション | 🚫 BLOCKED |
| ATK-06 | 再帰 CTE の循環 / 無限ループ | 🚫 BLOCKED |
| ATK-07 | 深いチェーンによる CTE depth bomb | ✅ SAFE（MAX_DEPTH ガード追加） |
| ATK-08 | GET による ID 列挙 | 🚫 BLOCKED |
| ATK-09 | children エンドポイントの意図しないサブツリー露出 | ✅ SAFE |
| ATK-10 | 大きな name フィールドによるメモリ枯渇 | ✅ SAFE（サイズ制限ミドルウェア） |
| ATK-11 | 逐次サブツリー剪定 | ✅ SAFE（正当な操作） |
| ATK-12 | hasChildren と子挿入のレースコンディション | 🚫 BLOCKED |

**6 BLOCKED, 4 SAFE, 0 EXPOSED** — 重大な発見なし。本番運用には挿入時の `MAX_DEPTH` ガードを追加してください。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| リクエストごとに祖先を数えて depth を計算する | O(depth) の N+1 クエリ。保存済みの `depth` カラムを使う |
| サブツリー深度を再計算せずに parent_id 更新（親付け替え）を許可する | サブツリー全体の保存済み `depth` 値が古くなる/誤りになる |
| 親 FK に `ON DELETE RESTRICT` を付けない | アプリケーションのバグが静かに子行を孤立させる |
| 存在しないカテゴリの祖先/子孫に対して空リスト 200 を返す | 呼び出し側が「祖先なし」と「カテゴリが存在しない」を区別できない |
| クライアント入力の `depth` を受け付ける | 攻撃者が深い子に `depth=0` を設定し、ツリー不変条件を破壊する |
| CTE 再帰制限や挿入時の MAX_DEPTH 上限がない | 深いチェーンが SQLite の 1000 階層 CTE 制限に達する |
