# ハウツー: オーナーシップ付きノート管理

> **FT リファレンス**: FT240 (`NENE2-FT/noteownerlog`) — オーナースコープ付きノート CRUD

`X-Auth-User` ヘッダーによるオーナーシップ検証を持つノート API を実証します。`WHERE id = ? AND owner_id = ?` による IDOR 防止、フィールドマージ更新（PUT で省略フィールドは既存値を保持）を実装します。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/notes` | ノートを作成する |
| `GET` | `/notes` | 現在のオーナーのノートを一覧表示する |
| `GET` | `/notes/{id}` | 単一ノートを取得する（オーナーのみ） |
| `PUT` | `/notes/{id}` | ノートを更新する（フィールドマージ） |
| `DELETE` | `/notes/{id}` | ノートを削除する（オーナーのみ） |

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_notes_owner ON notes(owner_id);
```

`owner_id` は `X-Auth-User` ヘッダーの文字列値です（本番では JWT の `sub` クレームに置き換えてください）。

---

## オーナー ID の取得

```php
private function ownerId(ServerRequestInterface $request): ?string
{
    $value = $request->getHeaderLine('X-Auth-User');

    return $value !== '' ? trim($value) : null;
}
```

ヘッダーが不在または空白のみの場合は `null` を返し、ハンドラーが 401 を返します。

---

## IDOR 防止: `findByIdAndOwner()`

```php
public function findByIdAndOwner(int $id, string $ownerId): ?Note
{
    /** @var array{id: int, owner_id: string, title: string, body: string, created_at: string, updated_at: string}|null $row */
    $row = $this->executor->fetchOne(
        'SELECT id, owner_id, title, body, created_at, updated_at
         FROM notes
         WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    );

    return $row !== null ? $this->hydrate($row) : null;
}
```

`WHERE id = ? AND owner_id = ?` は他のオーナーのノートが存在しても `null` を返します。ハンドラーは常に 404 を返します — 403 は「ノートが存在するが権限がない」ことを漏洩するためです。

---

## フィールドマージ更新（PUT）

フィールドマージ PUT は省略されたフィールドに既存の値を保持します:

```php
public function handle(ServerRequestInterface $request, array $args): ResponseInterface
{
    $ownerId = $this->ownerId($request);
    if ($ownerId === null) {
        return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401);
    }

    $id   = (int) $args['id'];
    $note = $this->notes->findByIdAndOwner($id, $ownerId);

    if ($note === null) {
        return $this->problems->create($request, 'not-found', 'Note Not Found', 404);
    }

    $body  = $request->getParsedBody();
    $title = isset($body['title']) && is_string($body['title']) && trim($body['title']) !== ''
        ? trim($body['title'])
        : $note->title;  // 省略された場合は既存値を使用

    $noteBody = isset($body['body']) && is_string($body['body'])
        ? $body['body']
        : $note->body;  // 省略された場合は既存値を使用

    $updated = $this->notes->update($id, $ownerId, $title, $noteBody);

    return $this->json->create($updated->toArray());
}
```

**フィールドマージの意図**: クライアントは `title` だけを更新するために `body` を送信する必要はありません。省略されたフィールドは変更されないままです。これは JSON Merge Patch（RFC 7396）とは異なります — `null` を送信してもフィールドは削除されません。

---

## リポジトリの更新メソッド

```php
public function update(int $id, string $ownerId, string $title, string $body): Note
{
    $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

    $this->executor->execute(
        'UPDATE notes SET title = ?, body = ?, updated_at = ? WHERE id = ? AND owner_id = ?',
        [$title, $body, $now, $id, $ownerId],
    );

    $note = $this->findByIdAndOwner($id, $ownerId);

    assert($note !== null);  // 前のチェックで存在を確認済み

    return $note;
}
```

UPDATE にも `AND owner_id = ?` が含まれています — SQL インジェクションでオーナーを上書きすることを防ぐ 2 層目の防御です。

---

## ATK アセスメント — クラッカーマインド攻撃テスト

### ATK-01 — X-Auth-User ヘッダー偽造 ⚠️ EXPOSED

**Attack**: 任意のユーザー ID を `X-Auth-User: other-user` として送信する。
**Result**: EXPOSED — ヘッダーは信頼できません。本番では JWT または署名付きセッションに置き換える必要があります。このデモでは意図的にシンプルな実装になっています。

---

### ATK-03 — IDOR: 他ユーザーのノートを読む 🚫 BLOCKED

**Attack**: `GET /notes/1` を別の `X-Auth-User` 値で送信する。
**Result**: BLOCKED — `WHERE id = ? AND owner_id = ?` が他のオーナーのノートに対して `null` を返し、404 レスポンスになります。

---

### ATK-04 — SQL インジェクション 🚫 BLOCKED

**Attack**: `X-Auth-User: ' OR '1'='1` のようなヘッダーを送信する。
**Result**: BLOCKED — すべてのクエリはプリペアドステートメントとパラメーターバインディングを使用しています。

---

### ATK-05 — 空のタイトルで更新 🚫 BLOCKED

**Attack**: `{"title": ""}` または `{"title": "   "}` を PUT で送信する。
**Result**: BLOCKED — `trim($body['title']) !== ''` チェックにより、空白のみのタイトルは既存値にフォールバックします。

---

### ATK-06 — X-Auth-User ヘッダーなし 🚫 BLOCKED

**Attack**: `X-Auth-User` ヘッダーなしでリクエストを送信する。
**Result**: BLOCKED — `ownerId()` が `null` を返し、401 Unauthorized が返されます。

---

### ATK-07 — なりすまし: 他のオーナーのノートを更新 ⚠️ EXPOSED

**Attack**: 有効なヘッダーで他のユーザーのノート ID を PUT で更新しようとする。
**Result**: EXPOSED — ATK-01 と同じ根本原因: `X-Auth-User` ヘッダーは偽造可能です。ただし `WHERE id = ? AND owner_id = ?` により、正しいオーナー ID なしではレコードが見つかりません（404 を返します）。

---

### ATK-08 — XSS ペイロード ✅ ACCEPTED BY DESIGN

**Attack**: `{"title": "<script>alert(1)</script>"}` を保存する。
**Result**: ACCEPTED BY DESIGN — API は JSON を返します。XSS のサニタイズはブラウザレンダリングレイヤーの責務です。JSON API は生データを保存・返却します。

---

### ATK-09 — フィールドマージの驚き ✅ ACCEPTED BY DESIGN

**Attack**: `{"title": "New Title"}` のみを送信すると `body` が空になると期待する。
**Result**: ACCEPTED BY DESIGN — フィールドマージ PUT は省略されたフィールドを保持します。これは意図的な動作です。クライアントがフィールドを明示的に空にしたい場合は `{"body": ""}` を送信する必要があります。

---

### ATK-10 — 数値以外の ID 🔶 PARTIALLY BLOCKED

**Attack**: `GET /notes/abc` のような数値以外の ID を送信する。
**Result**: PARTIALLY BLOCKED — `(int) $args['id']` は `'abc'` を `0` にキャストし、ID 0 のノートは存在しないため 404 になります。ただし、明示的な整数バリデーションの方が意図をより明確に示します。

---

### ATK-11 — 他のユーザーのノートを削除 🚫 BLOCKED

**Attack**: 他のオーナーのノート ID を DELETE で送信する。
**Result**: BLOCKED — `findByIdAndOwner()` が `null` を返し、DELETE は実行されずに 404 が返されます。

---

### ATK-12 — X-Auth-User に空白のみ 🚫 BLOCKED

**Attack**: `X-Auth-User:    ` （空白のみ）を送信する。
**Result**: BLOCKED — `trim($value) !== ''` チェックにより `null` が返され、401 になります。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|------|------|
| ATK-01 | X-Auth-User ヘッダー偽造 | ⚠️ EXPOSED |
| ATK-03 | IDOR: 他ユーザーのノートを読む | 🚫 BLOCKED |
| ATK-04 | SQL インジェクション | 🚫 BLOCKED |
| ATK-05 | 空のタイトルで更新 | 🚫 BLOCKED |
| ATK-06 | X-Auth-User ヘッダーなし | 🚫 BLOCKED |
| ATK-07 | なりすまし: 他ユーザーのノートを更新 | ⚠️ EXPOSED |
| ATK-08 | XSS ペイロード | ✅ ACCEPTED BY DESIGN |
| ATK-09 | フィールドマージの驚き | ✅ ACCEPTED BY DESIGN |
| ATK-10 | 数値以外の ID | 🔶 PARTIALLY BLOCKED |
| ATK-11 | 他のユーザーのノートを削除 | 🚫 BLOCKED |
| ATK-12 | X-Auth-User に空白のみ | 🚫 BLOCKED |

**7 BLOCKED / SAFE, 2 EXPOSED, 2 BY DESIGN**

主な発見: `X-Auth-User` ヘッダーは偽造可能です（ATK-01, ATK-07）。本番では JWT ベースの認証に置き換えてください。SQL レベルのオーナーフィルター（`WHERE id = ? AND owner_id = ?`）は他のすべての攻撃に対して機能しています。

---

## 関連 howto

- [`jwt-authentication.md`](jwt-authentication.md) — X-Auth-User を JWT に置き換える
- [`multi-tenant-isolation.md`](multi-tenant-isolation.md) — テナントスコープのデータ分離
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — リソースオーナーシップの強制
- [`note-management-with-tags.md`](note-management-with-tags.md) — タグ付きノート管理
