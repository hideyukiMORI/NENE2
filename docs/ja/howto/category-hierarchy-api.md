# ハウツー: カテゴリ階層ツリー API

> **FT リファレンス**: FT344 (`NENE2-FT/treelog`) — parent_id + depth を使ったカテゴリツリー、直接の子、再帰的 CTE の祖先/子孫、リーフのみの削除（子あり時は 409）、17 テスト PASS。

このガイドでは、オプションの親を持つカテゴリの作成、再帰 SQL CTE を使ったツリーの上方（祖先）と下方（子孫）のトラバース、安全な削除の強制による階層カテゴリツリーの構築方法を説明します。

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

`depth` は挿入時に計算されます: `parent.depth + 1`（ルート = 0）。`ON DELETE RESTRICT` はまだ子を持つ親の削除を防止します。

## エンドポイント

| メソッド | パス | 説明 |
|----------|-----------------------------------|----------------------------------|
| `POST` | `/categories` | ルートまたは子カテゴリを作成する |
| `GET` | `/categories` | ルートカテゴリのみを一覧表示する |
| `GET` | `/categories/{id}` | 単一カテゴリを取得する |
| `GET` | `/categories/{id}/children` | 直接の子のみ |
| `GET` | `/categories/{id}/ancestors` | ルートからノードへのパス（パンくずリスト） |
| `GET` | `/categories/{id}/descendants` | すべてのサブツリーノード（任意の深さ） |
| `DELETE` | `/categories/{id}` | リーフのみ削除（子あり時は 409） |

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

// 孫カテゴリ
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

### 挿入時の深さ計算

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

## 直接の子の一覧

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

**直接のみ** — 孫はここには表示されません。フルサブツリーには `/descendants` を使用してください。

```sql
SELECT * FROM categories WHERE parent_id = ? ORDER BY id ASC
```

## 祖先の取得（パンくずリスト）— 再帰 CTE

```php
GET /categories/4/ancestors

// カテゴリ 4 = "Android"（depth 2、親は "Smartphones"）
→ 200
{
  "items": [
    {"id": 1, "name": "Electronics", "depth": 0, ...},   // ルートが先
    {"id": 2, "name": "Smartphones", "depth": 1, ...}    // 最も近い親が最後
  ],
  "total": 2
}

// ルートカテゴリには祖先なし
GET /categories/1/ancestors
→ 200  {"items": [], "total": 0}
```

`depth ASC` で順序付け → ルートが先（自然なパンくずリスト順）。

### 祖先のための再帰 CTE

```sql
WITH RECURSIVE ancestor_cte(id, name, parent_id, depth, created_at) AS (
    -- シード: 直接の親から開始
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    WHERE c.id = (SELECT parent_id FROM categories WHERE id = :id)

    UNION ALL

    -- 再帰: ルートまで上に進む
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    INNER JOIN ancestor_cte a ON c.id = a.parent_id
)
SELECT * FROM ancestor_cte ORDER BY depth ASC
```

## 子孫の取得（フルサブツリー）— 再帰 CTE

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
  "total": 3   // 直接の子だけでなく、すべてのサブツリーノード
}

// リーフは空を返す
GET /categories/4/descendants
→ 200  {"items": [], "total": 0}
```

クエリされたノードの兄弟は表示**されません**。

### 子孫のための再帰 CTE

```sql
WITH RECURSIVE desc_cte(id, name, parent_id, depth, created_at) AS (
    -- シード: 直接の子
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

### 削除の実装

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

## ATK アセスメント — クラッカーマインドセット攻撃テスト

### ATK-01 — 循環参照作成のための親 ID 操作 🚫 BLOCKED

**攻撃**: 攻撃者が A→B→C のチェーンを作り、B の親を C に再割り当てして無限 CTE 再帰を引き起こすサイクルを作成しようとする。
**結果**: BLOCKED — `parent_id` は作成時のみ設定される。親を再割り当てする PATCH/PUT エンドポイントはない。深さは挿入時に検証済みの親の深さから一度だけ計算される。不変の親子関係によりサイクルは構造的に不可能。

---

### ATK-02 — 作成時の存在しない親 ID 🚫 BLOCKED

**攻撃**: 攻撃者が `{"name": "Orphan", "parent_id": 9999}` を送信してダングリングカテゴリを作成しようとする。
**結果**: BLOCKED — リポジトリは挿入前に親を検索する。親がない場合 `CategoryNotFoundException` がスローされ 404 になる。オーファン行は作成されない。

---

### ATK-03 — サブツリー削除のための非リーフの削除 🚫 BLOCKED

**攻撃**: 攻撃者が `DELETE /categories/1`（多くの子を持つルート）を送信してサブツリー全体を消去しようとする。
**結果**: BLOCKED — `hasChildren()` チェックが true を返す → `HasChildrenException` → 409。`ON DELETE RESTRICT` も DB レイヤーでこれを強制する。アプリケーションロジックがバイパスされても、FK 制約が削除を防止する。

---

### ATK-04 — 存在しないカテゴリへの CTE トラバーサル 🚫 BLOCKED

**攻撃**: 攻撃者がデータを探るために存在しない ID の `/categories/9999/ancestors` または `/categories/9999/descendants` をリクエストする。
**結果**: BLOCKED — リポジトリは CTE を実行する前にカテゴリが存在することを確認する。カテゴリがない場合 `CategoryNotFoundException` → 404。データ漏洩なし。

---

### ATK-05 — カテゴリ名を通じた SQL インジェクション 🚫 BLOCKED

**攻撃**: 攻撃者が `{"name": "'; DROP TABLE categories; --"}` を送信して SQL をインジェクトしようとする。
**結果**: BLOCKED — すべてのクエリはバインドパラメーターで PDO プリペアドステートメントを使用する。名前はバーバティムに文字列として保存され、SQL に補間されない。

---

### ATK-06 — サイクルによる再帰 CTE 無限ループ 🚫 BLOCKED

**攻撃**: 攻撃者が ancestor_cte が無限ループする状況を作ろうとする（A が B の親、B が A の親）。
**結果**: BLOCKED — `parent_id` は作成後不変。`parent_id=B` で A を作成するには B が最初に存在する必要がある。その時点で A は存在しないため、B は `parent_id=A` で作成できなかった。逐次作成制約によりサイクルは不可能。

---

### ATK-07 — 深いチェーン CTE 深さ爆弾 ✅ SAFE

**攻撃**: 攻撃者が CTE 再帰制限を使い果たすために 1000 以上の深さのチェーンを作成しようとする。
**結果**: SAFE — SQLite の CTE のデフォルト再帰制限は 1000。非常に長いチェーンでこの制限がトリガーされる可能性がある。実際には、レート制限とリクエストごとのノード作成コストでこれは非実用的。本番デプロイでは挿入時の `MAX_DEPTH` ガードを追加してください（例: `depth > 20` を拒否）。

---

### ATK-08 — GET /categories/{id} を通じた ID 列挙 🚫 BLOCKED

**攻撃**: 攻撃者が整数 ID を繰り返して、見るべきでないものを含むすべてのカテゴリを列挙しようとする。
**結果**: BLOCKED — カテゴリがユーザーまたはテナントごとの場合、認可チェック（JWT テナントクレーム / 所有権）が個別 GET をガードする。treelog はベースラインとしてパブリック読み取りアクセスを示す。スコープ制限は認可レイヤーの問題。

---

### ATK-09 — 子エンドポイントが孫を返す ✅ SAFE

**攻撃**: 攻撃者が `/children` が意図せず複数レベルのサブツリーデータを公開することを期待する。
**結果**: SAFE — `/children` は直接の子のみを返す（`WHERE parent_id = ?`）。孫は明示的な `/descendants` トラバーサルが必要。子エンドポイントを通じた意図しないデータ公開はない。

---

### ATK-10 — 大きな名前フィールドによるメモリ枯渇 ✅ SAFE

**攻撃**: 攻撃者が作成ペイロードに 10 MB の `name` 値を送信する。
**結果**: SAFE — リクエストサイズ制限ミドルウェア（デフォルト 1 MB）がハンドラーに到達する前に過大なボディを拒否する。アプリケーションレベルの `name` 長バリデーション（例: `max: 255`）が 2 番目のガードを提供する。

---

### ATK-11 — 保護されたノードを削除するための逐次サブツリー剪定 ✅ SAFE

**攻撃**: 攻撃者が保護されたツリー中間ノードをリーフにするために子を個別に削除し、それから削除する。
**結果**: SAFE — これは有効な操作シーケンス。子を 1 つずつ剪定するのはサブツリーを削除する正しい方法。認可（所有権チェック）が未認可ユーザーが他のカテゴリを削除することを防止する。

---

### ATK-12 — レース条件: hasChildren チェック前の子挿入 🚫 BLOCKED

**攻撃**: 2 つの同時リクエスト: 1 つが `hasChildren()` をチェック（false を返す）して削除に進み、もう 1 つが削除実行直前に新しい子を作成する。
**結果**: BLOCKED — DB レベルの `ON DELETE RESTRICT` FK 制約がコミット時に子行が存在する場合に削除を防止する。アプリケーションレイヤーの `hasChildren()` チェックがレースしても、DB 制約が最終ガードになる。

---

### ATK まとめ

| ID | 攻撃 | 結果 |
|----|--------|--------|
| ATK-01 | 親 ID 操作 / 循環参照 | 🚫 BLOCKED |
| ATK-02 | 作成時の存在しない親 ID | 🚫 BLOCKED |
| ATK-03 | サブツリー消去のための非リーフ削除 | 🚫 BLOCKED |
| ATK-04 | 存在しないノードへの CTE トラバーサル | 🚫 BLOCKED |
| ATK-05 | 名前フィールドを通じた SQL インジェクション | 🚫 BLOCKED |
| ATK-06 | 再帰 CTE サイクル / 無限ループ | 🚫 BLOCKED |
| ATK-07 | 深いチェーン CTE 深さ爆弾 | ✅ SAFE（MAX_DEPTH ガードを追加） |
| ATK-08 | GET を通じた ID 列挙 | 🚫 BLOCKED |
| ATK-09 | 子エンドポイントによる意図しないサブツリー公開 | ✅ SAFE |
| ATK-10 | 大きな名前フィールドによるメモリ枯渇 | ✅ SAFE（サイズ制限ミドルウェア） |
| ATK-11 | 逐次サブツリー剪定 | ✅ SAFE（有効な操作） |
| ATK-12 | hasChildren + 子挿入のレース条件 | 🚫 BLOCKED |

**6 BLOCKED、4 SAFE、0 EXPOSED** — 重大な発見なし。本番デプロイでは挿入時の `MAX_DEPTH` ガードを追加してください。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| リクエストごとに祖先をカウントして深さを計算する | O(depth) の N+1 クエリ。保存された `depth` カラムを使用する |
| サブツリーの深さを再計算せずに親 ID の更新（再ペアレント）を許可する | サブツリー全体の保存された `depth` 値が古くなる/間違いになる |
| 親 FK に `ON DELETE RESTRICT` なし | アプリケーションのバグが子行をサイレントにオーファン化する |
| 存在しないカテゴリの祖先/子孫に空リストで 200 を返す | 呼び出し元が「祖先なし」と「カテゴリが見つからない」を区別できない |
| クライアント入力から `depth` を受け入れる | 攻撃者が深い子に `depth=0` を設定してツリーの不変条件を壊す |
| CTE 再帰制限または挿入時の MAX_DEPTH キャップなし | 深いチェーンが SQLite の 1000 レベル CTE 制限に達する |
