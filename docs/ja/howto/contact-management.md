# How-to: 連絡先管理 API

> **FT 参照**: FT238 (`NENE2-FT/contactlog`) — 連絡先管理 API

所有者スコープ付き CRUD、多対多の連絡先グループシステム、`LIKE` 全文検索と `EXISTS` グループフィルターの組み合わせ、`DatabaseConstraintException` ハンドリングに支えられた冪等なグループメンバーシップ操作を持つ連絡先管理 API を示します。

---

## ルート

| Method   | Path                                                   | 説明                                   |
|----------|--------------------------------------------------------|----------------------------------------|
| `POST`   | `/owners/{ownerId}/contacts`                           | 連絡先を作成                           |
| `GET`    | `/owners/{ownerId}/contacts`                           | 連絡先を検索（オプションで `?q=`、`?group_id=`） |
| `GET`    | `/owners/{ownerId}/contacts/{id}`                      | 単一連絡先を取得                       |
| `PUT`    | `/owners/{ownerId}/contacts/{id}`                      | 連絡先を更新（完全置換）               |
| `DELETE` | `/owners/{ownerId}/contacts/{id}`                      | 連絡先を削除                           |
| `POST`   | `/owners/{ownerId}/groups`                             | グループを作成                         |
| `PUT`    | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}` | 連絡先をグループに追加              |
| `DELETE` | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}` | 連絡先をグループから削除            |

`{ownerId}` はすべての操作を 1 人の所有者にスコープします — 1 人の所有者が作成した連絡先とグループは他者から見えません。

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

主な設計上の選択:
- `contact_groups` は複合 `PRIMARY KEY (contact_id, group_id)` を使用しています —
  (contact, group) ペア毎に最大 1 行となります。重複の挿入を試みると制約エラーになります。
- `groups.UNIQUE(owner_id, name)` は 1 人の所有者内での重複グループ名を防ぎます。
- `email`、`phone`、`notes` は `''` をデフォルトとします — オプションフィールドの NULL ハンドリングが不要です。

---

## IDOR 防止: すべてのクエリに owner_id

すべての読み書き操作で `WHERE` 句に `owner_id` を含めます:

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

連絡先 5 が `bob` に属している場合、`/owners/alice/contacts/5` へのリクエストは
`null` を返し → `404 Not Found` になります。呼び出し側は「存在しない」と「あなたのものではない」を
区別できません — これにより ID 存在確認を防ぎます。

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

使用しているパターン:
- **動的な条件の蓄積**: 必須条件（`owner_id`）から始め、オプション条件を追加します。
  `implode(' AND ', $conditions)` が安全に結合します。
- **`LIKE ? OR LIKE ?`**: パラメーター化された LIKE — SQL インジェクションなし。`%` ワイルドカードは
  PHP 文字列内にあり、ユーザー入力にはありません。ただし `$query` 自体が `%` や `_` を含む場合、
  SQLite はそれらの文字を LIKE ワイルドカードとして解釈します — リテラルマッチが必要な場合は
  `str_replace(['%', '_'], ['\\%', '\\_'], $query)` でエスケープしてください。
- **`EXISTS (SELECT 1 ...)`**: 相関サブクエリにより、JOIN なしで指定グループに属する連絡先を
  フィルターします（連絡先が複数グループに属する場合の重複行を回避）。

---

## グループ作成: 重複名 → 409

`groups` の `UNIQUE(owner_id, name)` により、所有者内での重複グループ名は制約エラーになります。
リポジトリはそれをキャッチして `null` を返します:

```php
public function createGroup(string $ownerId, string $name): ?array
{
    try {
        $id = $this->db->insert(
            'INSERT INTO groups (owner_id, name, created_at) VALUES (?, ?, ?)',
            [$ownerId, $name, $now],
        );
    } catch (DatabaseConstraintException) {
        return null;  // この所有者にはすでに同名のグループが存在
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

`409` が正しいステータスです — リクエストは有効ですが既存リソースと衝突しています。

---

## グループメンバーシップ: 制約キャッチによる冪等な追加

連絡先をグループに追加する操作は冪等です — 繰り返し呼び出してもエラーなく成功します:

```php
public function addToGroup(int $contactId, int $groupId, string $ownerId): bool
{
    // 連絡先とグループの両方がこの所有者に属することを検証
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
        // PRIMARY KEY 違反 — 連絡先はすでにグループに所属。成功として扱う（冪等）。
    }

    return true;
}
```

複合 `PRIMARY KEY (contact_id, group_id)` が DB レベルで一意性を強制します。
catch-and-ignore パターンにより操作を複数回安全に呼べるようになります — すでに存在するメンバーシップは
呼び出し側視点ではエラーではありません。

メンバーシップ挿入前に `contact` と `group` の両方が `$ownerId` に属することを検証します。
クロス所有者メンバーシップ（alice の連絡先が bob のグループに追加される）は防がれます。

---

## グループメンバーシップの削除

削除は連絡先の所有権を検証し、メンバーシップが存在すれば削除します:

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

    return $count > 0;  // メンバーシップが存在しなければ false → 404
}
```

メンバーシップが存在しないときに `false` を返すと `404` になります。これは正しい動作です:
呼び出し側は存在しないものを削除しようとしたためです。

---

## 関連 howto

- [`group-membership-management.md`](group-membership-management.md) — ロールベースのグループメンバーシップパターン
- [`tagging-system.md`](tagging-system.md) — 多対多のタグリレーション
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR 防止パターン
- [`use-fts5-search.md`](use-fts5-search.md) — より大きなデータセット向けの全文検索
