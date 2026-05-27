# ハウツー: ソフトデリート、リストア、完全削除

> **FT リファレンス**: `NENE2-FT/softdelete` — `deleted_at` タイムスタンプによるソフトデリート、リストア（ソフトデリートされたノートのみリストア可能）、完全ハードデリート（ソフトデリートされたノートのみ完全削除可能）、14 テスト PASS。

このガイドでは、3 つの削除ステート（アクティブ、ソフトデリート（復元可能）、完全削除（消去済み））の実装方法を解説します。専用のゴミ箱ビューと一括パージを追加した `docs/howto/soft-delete-trash-restore.md`（FT340 softdeletelog）と比較してください。

## スキーマ

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    deleted_at TEXT             -- NULL = アクティブ; タイムスタンプ = ソフトデリート
);

CREATE INDEX idx_notes_deleted ON notes(deleted_at);
```

`deleted_at IS NULL` → アクティブ。`deleted_at IS NOT NULL` → ソフトデリート済み。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|------|
| `POST`   | `/notes`                 | ノートを作成する                          |
| `GET`    | `/notes`                 | アクティブなノートのみを一覧表示する      |
| `GET`    | `/notes/{id}`            | ノートを取得する（削除済みの場合は 404）  |
| `DELETE` | `/notes/{id}`            | ソフトデリートする（deleted_at を設定）   |
| `POST`   | `/notes/{id}/restore`    | ソフトデリートされたノートをリストアする  |
| `DELETE` | `/notes/{id}/permanent`  | ソフトデリートされたノートを完全削除する  |

## ノートの作成

```php
POST /notes  {"title": "My Note", "body": "Some content"}

→ 201
{
  "id": 1,
  "title": "My Note",
  "body": "Some content",
  "deleted_at": null,    // ← null = アクティブ
  "created_at": "..."
}
```

## アクティブなノートの一覧表示

```php
GET /notes
→ 200  {"items": [{...アクティブなノート...}], "total": 2}
```

`deleted_at IS NULL` のノートのみを返します。ソフトデリートされたノートはここでは見えません。

## ソフトデリート

```php
DELETE /notes/1
→ 200  // deleted_at = now を設定

// ソフトデリートされたノートはアクティブリストから消える
GET /notes
→ 200  {"items": [], "total": 0}

// 直接 GET からも消える
GET /notes/1
→ 404
```

```sql
UPDATE notes SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL
```

## リストア

```php
// ソフトデリートされたノートをリストアする
POST /notes/1/restore
→ 200  {"id": 1, "title": "My Note", "deleted_at": null, ...}  // アクティブに戻る

// リストアされたノートがアクティブリストに再び現れる
GET /notes
→ 200  {"items": [{...}], "total": 1}
```

### アクティブなノートのリストア → 404

```php
// アクティブな（ソフトデリートされていない）ノートをリストアしようとする → 404
POST /notes/2/restore   // ノート 2 は一度も削除されていない
→ 404
```

ソフトデリートされたノートのみリストアできます。アクティブなノートはリストア時に 404 を返します。

```sql
UPDATE notes SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL
-- 0 行が影響を受けた場合 → ノートはアクティブか存在しない → 404
```

## 完全削除

```php
// 最初にソフトデリートが必要
DELETE /notes/1   // ソフトデリート
POST /notes/1/restore  // リストア（オプション）

// ソフトデリートされたノートを完全削除
DELETE /notes/1          // 最初にソフトデリート
DELETE /notes/1/permanent
→ 200  {"permanent": true}

GET /notes/1
→ 404  // 永遠に消去
```

### アクティブなノートの完全削除 → 404

```php
// アクティブなノートを完全削除する → 404
// 最初にソフトデリートし、次に完全削除する必要がある
DELETE /notes/2/permanent   // ノート 2 はアクティブ
→ 404
```

```sql
DELETE FROM notes WHERE id = ? AND deleted_at IS NOT NULL
-- 0 行が影響を受けた場合 → ノートはアクティブか存在しない → 404
```

## 状態図

```
Active（アクティブ）
  │
  │ DELETE /notes/{id}     （ソフトデリート）
  ▼
Soft-deleted（ソフトデリート済み）
  │           │
  │ POST      │ DELETE
  │ /restore  │ /permanent
  ▼           ▼
Active      Gone（完全削除済み）
```

**重要な不変条件**: 完全削除には事前のソフトデリートが必要です。これにより、アクティブ状態からの誤ったハードデリートを防ぎます。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| アクティブなノートの完全削除を許可する | ソフトデリートの安全ネットをスキップする; 復元ウィンドウなしにデータが消える |
| アクティブなノートのリストアに 200 を返す | 呼び出し元がリストアが必要だったかわからない; 404 を使って「ゴミ箱にない」ことを伝える |
| `deleted_at` にインデックスなし | すべての一覧クエリでフルテーブルスキャン; インデックスなしで `WHERE deleted_at IS NULL` が遅くなる |
| `DELETE /notes/{id}` で即座にハードデリートする | 復元不可能; まずソフトデリートを使う |
| アクティブリストで `deleted_at` を公開する | クライアントがフィールドを見る; レスポンスが視覚的に混雑する; フィルタリングするか `null` を使う |
