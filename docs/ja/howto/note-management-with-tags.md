# ハウツー: タグ付きノート管理

## 概要

このガイドでは NENE2 を使ったタグ付きノート管理 API の構築方法を解説します。ユーザーごとの分離、タグベースフィルタリング、全文キーワード検索、オーナーシップ強制 CRUD を実装します。

**参照実装**: `../NENE2-FT/notelog/`

---

## スキーマ設計

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS note_tags (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    note_id INTEGER NOT NULL,
    tag     TEXT    NOT NULL,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    UNIQUE (note_id, tag)
);
```

`ON DELETE CASCADE` はノート削除時にタグを自動的に削除します。

---

## ルートテーブル

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/notes` | ユーザー | ノートを作成する |
| `GET` | `/notes` | ユーザー | 自分のノートを一覧表示する（`?tag=` または `?q=` でフィルタ可能） |
| `GET` | `/notes/{id}` | ユーザー | 単一ノートを取得する |
| `PUT` | `/notes/{id}` | ユーザー | ノートフィールドを更新する |
| `DELETE` | `/notes/{id}` | ユーザー | ノートを削除する |

---

## タグフィルタリング

`JOIN` でタグフィルタリングを行います:

```sql
SELECT n.* FROM notes n
JOIN note_tags t ON t.note_id = n.id
WHERE n.user_id = :uid AND t.tag = :tag
ORDER BY n.id DESC
```

---

## キーワード検索

`LIKE` を使ってタイトルと本文の全文検索を行います:

```sql
SELECT * FROM notes
WHERE user_id = :uid AND (title LIKE :kw OR body LIKE :kw)
ORDER BY id DESC
```

`:kw` プレースホルダーは `'%' . $keyword . '%'` です。パラメーター化クエリが SQL インジェクションを防ぎます。

---

## タグのパース

タグは文字列の配列でなければなりません。小文字に正規化します:

```php
private function parseTags(mixed $raw): ?array
{
    if (!is_array($raw)) return [];
    $tags = [];
    foreach ($raw as $tag) {
        if (!is_string($tag)) return null;   // 非文字列は 422 で拒否
        $t = trim($tag);
        if ($t !== '') $tags[] = strtolower($t);
    }
    return $tags;
}
```

---

## IDOR / オーナーシップパターン

すべての読み書き操作は `user_id` でスコープされます。読み取りでは存在を明かさないために 404 を返し（403 ではなく）、書き込みではリソースが存在するが権限がないことをユーザーに伝えるために 403 を返します:

```php
// 読み取り: 情報漏洩を防ぐために 404 を返す
if ((int) $note['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Note not found.');
}

// 書き込み: リソースが存在するが所有していない場合は 403
if ((int) $note['user_id'] !== $userId) {
    return 'forbidden';
}
```

---

## 部分更新（PUT）

フィールドに `null` を指定すると「変更なし」を意味します:

```php
$title    = isset($body['title']) ? trim((string) $body['title']) : null;
$noteBody = isset($body['body']) ? (string) $body['body'] : null;
$tags     = (isset($body['tags'])) ? $this->parseTags($body['tags']) : null;
```

リポジトリでは非 null のフィールドのみ更新します。

---

## HTTP ステータスコード

| 状況 | ステータス |
|------|----------|
| ノート作成完了 | 201 |
| ノート取得 / 一覧 | 200 |
| ノート更新 / 削除 | 200 |
| X-User-Id なし | 400 |
| タイトルが空 | 422 |
| タグに非文字列値 | 422 |
| ノートが見つからない（または IDOR） | 404 |
| 他人のノートを更新/削除 | 403 |
