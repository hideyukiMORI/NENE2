# ハウツー: 連絡先管理 API

> **FT リファレンス**: FT238 (`NENE2-FT/contactlog`) — 連絡先管理 API

オーナースコープ CRUD、多対多の連絡先グループシステム、`EXISTS` グループフィルタリングと組み合わせた `LIKE` 全文検索、`DatabaseConstraintException` 処理によって支えられた冪等なグループメンバーシップ操作を持つ連絡先管理 API を示します。

---

## ルート

| メソッド | パス | 説明 |
|----------|--------------------------------------------------------|----------------------------------------|
| `POST` | `/owners/{ownerId}/contacts` | 連絡先を作成する |
| `GET` | `/owners/{ownerId}/contacts` | 連絡先を検索する（オプション `?q=`、`?group_id=`） |
| `GET` | `/owners/{ownerId}/contacts/{id}` | 単一連絡先を取得する |
| `PUT` | `/owners/{ownerId}/contacts/{id}` | 連絡先を更新する（完全置換） |
| `DELETE` | `/owners/{ownerId}/contacts/{id}` | 連絡先を削除する |
| `POST` | `/owners/{ownerId}/groups` | グループを作成する |
| `PUT` | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}` | グループに連絡先を追加する |
| `DELETE` | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}` | グループから連絡先を削除する |

`{ownerId}` はすべての操作を 1 人のオーナーにスコープします — あるオーナーが作成した連絡先とグループは他のオーナーには見えません。

---

## スキーマ: contacts、groups、contact_groups

```sql
CREATE TABLE IF NOT EXISTS contacts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   TEXT    NOT NULL,
    name       TEXT    NOT NULL,
    email      TEXT    NOT NULL DEFAULT '',
    phone      TEXT    NOT NULL DEFAULT '',
    notes      TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_contacts_owner ON contacts (owner_id);

CREATE TABLE IF NOT EXISTS groups (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   TEXT    NOT NULL,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE(owner_id, name)
);

CREATE TABLE IF NOT EXISTS contact_groups (
    contact_id INTEGER NOT NULL,
    group_id   INTEGER NOT NULL,
    PRIMARY KEY (contact_id, group_id)
);
```

主要な設計上の選択:
- `contact_groups` は複合 `PRIMARY KEY (contact_id, group_id)` を使用します — (連絡先、グループ) ペアごとに最大 1 行が存在できます。重複挿入を試みると制約エラーが発生します。
- `groups.UNIQUE(owner_id, name)` は 1 人のオーナー内での重複グループ名を防止します。
- `email`、`phone`、`notes` はデフォルト `''` — オプションフィールドで NULL 処理は不要。

---

## IDOR 防止: すべてのクエリに owner_id

すべての読み取りと書き込み操作に `WHERE` 句に `owner_id` が含まれます:

```php
public function findById(int $id, string $ownerId): ?Contact
{
    $rows = $this->db->fetchAll(
        'SELECT * FROM contacts WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    );

    return $rows !== [] ? $this->hydrateWithGroups($rows[0]) : null;
}
```

連絡先 5 が `bob` に属する場合の `/owners/alice/contacts/5` へのリクエストは `null` → `404 Not Found` を返します。呼び出し元は「存在しない」と「あなたのものではない」を区別できません — これにより ID の存在確認が防止されます。

---

## 検索: 動的 LIKE + EXISTS フィルター

一覧エンドポイントはオプションのクエリパラメーターに基づいて動的 `WHERE` 句を構築します:

```php
public function search(string $ownerId, ?string $query, ?string $groupId): array
{
    $conditions = ['c.owner_id = ?'];
    $bindings   = [$ownerId];

    if ($query !== null) {
        $conditions[] = '(c.name LIKE ? OR c.email LIKE ?)';
        $bindings[]   = "%{$query}%";
        $bindings[]   = "%{$query}%";
    }

    if ($groupId !== null) {
        $conditions[] = 'EXISTS (SELECT 1 FROM contact_groups cg WHERE cg.contact_id = c.id AND cg.group_id = ?)';
        $bindings[]   = (int) $groupId;
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);
    $rows  = $this->db->fetchAll(
        "SELECT c.* FROM contacts c {$where} ORDER BY c.name ASC",
        $bindings,
    );

    return array_map(fn (array $row) => $this->hydrateWithGroups($row), $rows);
}
```

使用されるパターン:
- **動的条件の蓄積**: 必須条件（`owner_id`）から始め、オプションを追加します。`implode(' AND ', $conditions)` で安全に結合します。
- **`LIKE ? OR LIKE ?`**: パラメータ化 LIKE — SQL インジェクションなし。`%` ワイルドカードは PHP 文字列内にあり、ユーザー入力にはありません。ただし `$query` 自体に `%` または `_` が含まれる場合、SQLite はこれらを LIKE ワイルドカードとして解釈します。リテラルマッチングが必要な場合は `str_replace(['%', '_'], ['\\%', '\\_'], $query)` でエスケープしてください。
- **`EXISTS (SELECT 1 ...)`**: 相関サブクエリは JOIN なしで指定グループに属する連絡先をフィルタリングします（連絡先が複数グループに属する場合の重複行を避けます）。

---

## グループ作成: 重複名 → 409

`groups` の `UNIQUE(owner_id, name)` により、オーナー内での重複グループ名が制約エラーになります。リポジトリはそれをキャッチして `null` を返します:

```php
public function createGroup(string $ownerId, string $name): ?array
{
    try {
        $id = $this->db->insert(
            'INSERT INTO groups (owner_id, name, created_at) VALUES (?, ?, ?)',
            [$ownerId, $name, $now],
        );
    } catch (DatabaseConstraintException) {
        return null;  // このオーナーのグループ名がすでに存在する
    }
    // ...
}
```

コントローラーは `null` を `409 Conflict` にマップします:

```php
$group = $this->repo->createGroup($ownerId, $name);

if ($group === null) {
    return $this->problems->create($request, 'conflict', 'Group Already Exists', 409,
        "Group {$name} already exists.");
}
```

`409` が正しいステータスです — リクエストは有効ですが既存のリソースと競合します。

---

## グループメンバーシップ: 制約キャッチによる冪等な追加

グループへの連絡先の追加は冪等です — 繰り返し呼び出してもエラーなしに成功します:

```php
public function addToGroup(int $contactId, int $groupId, string $ownerId): bool
{
    // 連絡先とグループの両方がこのオーナーに属することを確認する
    $contact = $this->db->fetchOne('SELECT id FROM contacts WHERE id = ? AND owner_id = ?', [$contactId, $ownerId]);
    $group   = $this->db->fetchOne('SELECT id FROM groups WHERE id = ? AND owner_id = ?', [$groupId, $ownerId]);

    if ($contact === null || $group === null) {
        return false;  // → 404 Not Found
    }

    try {
        $this->db->execute(
            'INSERT INTO contact_groups (contact_id, group_id) VALUES (?, ?)',
            [$contactId, $groupId],
        );
    } catch (DatabaseConstraintException) {
        // PRIMARY KEY 違反 — 連絡先はすでにグループに存在する。成功として扱う（冪等）。
    }

    return true;
}
```

複合 `PRIMARY KEY (contact_id, group_id)` が DB レベルで一意性を強制します。キャッチアンドイグナアパターンにより操作を複数回安全に呼び出せます — すでに存在するメンバーシップは呼び出し元の観点からはエラーではありません。

`contact` と `group` の両方がメンバーシップを挿入する前に `$ownerId` に属することが確認されます。クロスオーナーのメンバーシップ（alice の連絡先が bob のグループに追加される）が防止されます。

---

## グループメンバーシップの削除

削除は連絡先の所有権を確認し、メンバーシップが存在する場合に削除します:

```php
public function removeFromGroup(int $contactId, int $groupId, string $ownerId): bool
{
    $contact = $this->db->fetchOne('SELECT id FROM contacts WHERE id = ? AND owner_id = ?', [$contactId, $ownerId]);
    if ($contact === null) {
        return false;  // → 404
    }

    $count = $this->db->execute(
        'DELETE FROM contact_groups WHERE contact_id = ? AND group_id = ?',
        [$contactId, $groupId],
    );

    return $count > 0;  // メンバーシップが存在しなかった場合は false → 404
}
```

メンバーシップが存在しない場合に `false` を返すことで `404` になります。これは正しいです: 呼び出し元が存在しないものを削除しようとしました。

---

## 関連ハウツー

- [`group-membership-management.md`](group-membership-management.md) — ロールベースのグループメンバーシップパターン
- [`tagging-system.md`](tagging-system.md) — 多対多のタグリレーション
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR 防止パターン
- [`use-fts5-search.md`](use-fts5-search.md) — 大規模データセットの全文検索
