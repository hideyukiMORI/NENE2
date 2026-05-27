# ハウツー: PATCH 部分更新（JSON Merge Patch）

> **FT リファレンス**: FT326 (`NENE2-FT/patchlog`) — JSON Merge Patch（RFC 7396）部分更新: null フィールドのリセット、イミュータブルフィールドの拒否、ETag/If-Match、オーナーのみのミューテーション、42 テスト / 141 アサーション PASS。

このガイドでは JSON Merge Patch セマンティクスに従った `PATCH` エンドポイントの実装方法を解説します: 提供されたフィールドのみが更新され、`null` はデフォルトにリセットされ、イミュータブルフィールドは拒否されます。

## スキーマ

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    status     TEXT    NOT NULL DEFAULT 'draft',
    version    INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST`  | `/documents` | 作成する（`X-User-Id` 必須） |
| `GET`   | `/documents` | 一覧表示する |
| `GET`   | `/documents/{id}` | ETag ヘッダー付きで取得する |
| `PATCH` | `/documents/{id}` | 部分更新する（`X-User-Id` 必須） |
| `DELETE`| `/documents/{id}` | 削除する（オーナーのみ） |

## 作成

```php
POST /documents  X-User-Id: 1
{"title": "My Doc", "body": "Content"}
→ 201  {"id": 1, "owner_id": 1, "title": "My Doc", "status": "draft", "version": 1}

// X-User-Id なし → 401
// title なし → 422
// タイトルが空 → 422
// body はオプション → デフォルト ""
```

## ETag 付き GET

```php
GET /documents/1
→ 200  ETag: "doc-1-1"
{"id": 1, "title": "My Doc", "version": 1, ...}
```

ETag フォーマット: `"doc-{id}-{version}"`。

## PATCH — JSON Merge Patch セマンティクス

```php
// タイトルのみ更新 — body は変更なし
PATCH /documents/1  X-User-Id: 1
{"title": "Updated"}
→ 200  {"title": "Updated", "body": "Content", ...}

// body のみ更新
PATCH /documents/1  X-User-Id: 1
{"body": "New content"}
→ 200  {"title": "Updated", "body": "New content", ...}

// 空 {} — ノーオペレーション（RFC 7396 §3 に従い有効）
PATCH /documents/1  X-User-Id: 1
{}
→ 200  (変更なしのドキュメント)

// null はフィールドをデフォルトにリセット
PATCH /documents/1  X-User-Id: 1
{"status": null}
→ 200  {"status": "draft"}   // デフォルトにリセット
```

## イミュータブルフィールド — 拒否

一部のフィールドは PATCH で変更してはなりません:

```php
PATCH /documents/1  {"id": 999}         → 422  // イミュータブル
PATCH /documents/1  {"owner_id": 99}    → 422  // イミュータブル
PATCH /documents/1  {"version": 999}    → 422  // イミュータブル
PATCH /documents/1  {"created_at": "…"} → 422  // イミュータブル
```

## オーナーのみの認可

```php
// ユーザー 2 がユーザー 1 のドキュメントをパッチしようとする → 404（列挙を防ぐために 403 ではない）
PATCH /documents/1  X-User-Id: 2  {"title": "Stolen"}  → 404

// オーナーは常に自分のものをパッチできる
PATCH /documents/1  X-User-Id: 1  {"title": "Mine"}    → 200
```

## ETag / If-Match

```php
// 条件付き PATCH — バージョンが変わった場合は 412
PATCH /documents/1  X-User-Id: 1  If-Match: "doc-1-1"
{"title": "Updated"}
→ 200  // バージョンがまだ 1 の場合

PATCH /documents/1  X-User-Id: 1  If-Match: "doc-1-1"
{"title": "Stale"}
→ 412  // バージョンが 2 になっている場合
```

## 型バリデーション

```php
PATCH /documents/1  {"title": 123}   → 422  // 文字列ではなく int
PATCH /documents/1  {"body": [1,2]}  → 422  // 文字列ではなく array
```

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| フィールドなしを `null` と同様に扱う | 呼び出し元がフィールドをクリアできない; Merge Patch では `undefined` ≠ `null` |
| `owner_id` のパッチを許可する | 認可フローなしの API 経由のオーナーシップ移転 |
| クロスオーナーアクセスに 403 を返す | ドキュメントの存在を明かす; 代わりに 404 を返す |
| PATCH でドキュメント全体を置き換える | クライアントが変更するつもりのないフィールドを上書きする |
| イミュータブルフィールドをサイレントに受け付ける（ノーオペレーション） | クライアントが `id` を変更したと思う; サイレントな失敗が混乱を引き起こす |
