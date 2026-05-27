# ハウツー: ソフトデリート、ゴミ箱 & リストア API

> **FT リファレンス**: FT340 (`NENE2-FT/softlog`) — ソフトデリート（deleted_at）、ゴミ箱ビュー、リストア、完全ハードデリート、一括パージ、ピン優先順序付け、ATK クラッカー攻撃アセスメント付きのノート API、26 テスト / 60+ アサーション PASS。

このガイドでは、2 段階の削除ライフサイクルの実装方法を解説します: アイテムは最初にソフトデリート（ゴミ箱に移動）されてリストア可能で、その後明示的なハードデリートまたは一括パージによって完全に消去されます。

## スキーマ

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_pinned  INTEGER NOT NULL DEFAULT 0,
    deleted_at TEXT,               -- NULL = アクティブ; ソフトデリート時は ISO 8601
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

`deleted_at IS NULL` = アクティブ; `deleted_at IS NOT NULL` = ソフトデリート済み（ゴミ箱）。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|------|
| `POST` | `/notes` | ノートを作成する |
| `GET`  | `/notes` | アクティブなノートを一覧表示する（ピン優先） |
| `GET`  | `/notes/{id}` | アクティブなノートを取得する |
| `PUT`  | `/notes/{id}` | アクティブなノートを更新する |
| `DELETE` | `/notes/{id}` | ソフトデリートする（ゴミ箱へ） |
| `GET`  | `/notes/trash` | ゴミ箱のノートを一覧表示する |
| `POST` | `/notes/{id}/restore` | ゴミ箱からリストアする |
| `DELETE` | `/notes/{id}/permanent` | ハードデリートする（完全） |
| `POST` | `/notes/trash/purge` | ゴミ箱をすべてパージする |

## ノートの作成

```php
POST /notes
{"title": "My Note", "body": "Content", "is_pinned": false}
→ 201
{
  "id": 1,
  "title": "My Note",
  "body": "Content",
  "is_pinned": false,
  "deleted_at": null,
  "created_at": "..."
}

POST /notes  {"body": "No title"}  → 422  // title 必須
```

## アクティブなノートの一覧表示（ピン優先）

```php
GET /notes
→ 200
{
  "total": 3,
  "items": [
    {"id": 2, "title": "Pinned", "is_pinned": true, ...},
    {"id": 1, "title": "Normal A", ...},
    {"id": 3, "title": "Normal B", ...}
  ]
}
```

```sql
SELECT * FROM notes WHERE deleted_at IS NULL
ORDER BY is_pinned DESC, created_at DESC
```

ソフトデリートされたノートはアクティブリストに返されません。

## ノートの取得

```php
GET /notes/1
→ 200  {"id": 1, "title": "My Note", ...}

// ソフトデリート済みまたは未知 → 同じ 404
GET /notes/9999    → 404
GET /notes/1 (DELETE /notes/1 後)  → 404
```

## ノートの更新

```php
PUT /notes/1
{"title": "Updated", "body": "New body", "is_pinned": true}
→ 200  {"title": "Updated", "is_pinned": true, ...}

// ソフトデリートされたノートは更新不可
PUT /notes/1  (DELETE /notes/1 後)  → 404
```

## ソフトデリート

```php
DELETE /notes/1
→ 204  (ボディなし)

// ノートは GET /notes と GET /notes/1 から消える
// しかし GET /notes/trash には現れる

DELETE /notes/9999  → 404  // 見つからない
```

## ゴミ箱ビュー

```php
GET /notes/trash
→ 200
{
  "total": 1,
  "items": [
    {"id": 1, "title": "Gone", "deleted_at": "2026-05-27T10:00:00Z", ...}
  ]
}

// アクティブなノートはゴミ箱にない
```

すべてのゴミ箱アイテムで `deleted_at` は非 null です。

## リストア

```php
POST /notes/1/restore
→ 200  {"id": 1, "title": "Restore Me", "deleted_at": null, ...}

// リストアされたノートが GET /notes に再び現れる
// POST /notes/9999/restore  → 404
```

## ハードデリート（完全）

```php
DELETE /notes/1/permanent
→ 204  (ボディなし; ノートは DB から消える)

// ゴミ箱からも消える
// DELETE /notes/9999/permanent  → 404
```

## ゴミ箱のパージ

```php
POST /notes/trash/purge
→ 200  {"purged": 2}

// 空のゴミ箱
POST /notes/trash/purge  → 200  {"purged": 0}
```

`purge` は `DELETE FROM notes WHERE deleted_at IS NOT NULL` を発行し、行数を返します。

---

## ATK アセスメント — クラッカー攻撃テスト

### ATK-01 — ソフトデリートなしのハードデリート 🚫 BLOCKED

**攻撃**: 攻撃者がアクティブな（まだソフトデリートされていない）ノートに `DELETE /notes/1/permanent` を呼び出す。
**結果**: BLOCKED — `DELETE /notes/{id}/permanent` は処理を進める前に `deleted_at IS NOT NULL` を確認します。アクティブなノートは永続デリートエンドポイントに 404 を返します; ゴミ箱のアイテムのみハードデリートできます。

---

### ATK-02 — 直接 GET でソフトデリートされたノートにアクセス ✅ SAFE

**攻撃**: 攻撃者がノート ID 5 がソフトデリートされたことを知り、保護されたコンテンツを読もうとして `GET /notes/5` を呼び出す。
**結果**: SAFE — `GET /notes/{id}` は `WHERE id = ? AND deleted_at IS NULL` でクエリします。ソフトデリートされたノートは未知のノートと同様に 404 を返します — 存在のヒントなし。

---

### ATK-03 — 認証なしのゴミ箱パージ（大量破壊） ⚠️ EXPOSED

**攻撃**: 任意のクライアントが `POST /notes/trash/purge` を呼び出してすべてのユーザーのゴミ箱にあるすべてのノートを完全に破壊する。
**結果**: EXPOSED — `POST /notes/trash/purge` に認証チェックがありません。ユーザーごとのスコープなしでは、未認証クライアントがすべてのユーザーのすべてのゴミ箱データを不可逆的に削除できます。緩和策: 認証を要求する; パージを認証済みユーザー自身のゴミ箱にスコープする; グローバルパージには管理者ロールを要求する。

---

### ATK-04 — 二重ソフトデリートで deleted_at を破損 ✅ SAFE

**攻撃**: 攻撃者が `DELETE /notes/1` を 2 回送信し、2 回目の呼び出しが `deleted_at` を後のタイムスタンプにリセットすることを望む。
**結果**: SAFE — 最初の削除が `deleted_at` を設定します。2 回目の削除は `deleted_at IS NULL = false` を見つけるため、ルックアップが 0 行を返します → 404。タイムスタンプは変更されません。

---

### ATK-05 — アクティブなノートのリストア（ステート破損） 🚫 BLOCKED

**攻撃**: 攻撃者がアクティブな（削除されていない）ノートに `POST /notes/1/restore` を呼び出して `deleted_at = null` を無条件に強制する。
**結果**: BLOCKED — `restore` は `WHERE id = ? AND deleted_at IS NOT NULL` でクエリします。アクティブなノートはマッチしません → 404。べき等: すでにアクティブなノートのリストアは no-op 404 です。

---

### ATK-06 — 作成時のタイトルへの SQL インジェクション ✅ SAFE

**攻撃**: 攻撃者が `{"title": "'; DROP TABLE notes; --"}` を送信してデータベースを破損させる。
**結果**: SAFE — すべての書き込みはパラメーター化ステートメントを使用します。タイトルはリテラル文字列として保存されます。

---

### ATK-07 — ノート ID のオーバーフローでバリデーションをスキップ 🚫 BLOCKED

**攻撃**: 攻撃者が `GET /notes/99999999999999999999`（20 桁）を送信して PHP 整数をオーバーフローさせ意図しない ID に到達する。
**結果**: BLOCKED — ノート ID は変換前に `ctype_digit` + `strlen <= 18` で検証されます。オーバーフロー値 → 422。

---

### ATK-08 — 削除されたノートの更新（ゴーストへの書き込み） 🚫 BLOCKED

**攻撃**: 攻撃者が削除されたノートへの古いセッション参照を持ち、PUT を送信して変更しようとする。
**結果**: BLOCKED — `PUT /notes/{id}` は `WHERE id = ? AND deleted_at IS NULL` でクエリします。ソフトデリートされたノートはこのチェックに失敗します → 404。更新は拒否されます。

---

### ATK-09 — レース: リストアして即座にパージ 🚫 BLOCKED

**攻撃**: 攻撃者が `POST /notes/1/restore` と `POST /notes/trash/purge` をレースさせてリストア中のノートを破壊する。
**結果**: BLOCKED — 各操作は単一のアトミック DB トランザクションです。パージは `DELETE WHERE deleted_at IS NOT NULL` を発行; リストアは `deleted_at = NULL` を設定します。どちらかが勝ち、ノートは一貫した状態になります。

---

### ATK-10 — 同時ソフトデリートでオーファンを残す ✅ SAFE

**攻撃**: 2 つのリクエストが同時に `DELETE /notes/1` を呼び出します。両方が `deleted_at IS NULL` を確認し、両方が null を見て、両方が `deleted_at` を設定しようとします。
**結果**: SAFE — 最初の更新が成功します。2 番目は `deleted_at IS NOT NULL`（または 0 行更新）を見つけます → 404。SQLite は書き込みをシリアライズします; 2 番目の呼び出しは DB レベルでべき等です。

---

### ATK-11 — タイトルが長すぎる（ストレージ悪用） ⚠️ EXPOSED

**攻撃**: 攻撃者がデータベースストレージを枯渇させるために 10 MB のタイトル文字列を送信する。
**結果**: EXPOSED — `title` または `body` に最大長が強制されていません。緩和策: `MAX_TITLE_LENGTH`（例: 500 文字）と `MAX_BODY_LENGTH`（例: 100,000 文字）を追加し、超過した場合は 422 を返してください。リクエストサイズミドルウェアが二次ガードを提供します。

---

### ATK-12 — ピンオーバーフロー（ピン付きノートのフラッド） ⚠️ EXPOSED

**攻撃**: 攻撃者が何千ものピン付きノートを作成して実際のノートをすべてアクティブリストの上部から押し出す。
**結果**: EXPOSED — ピン付きノート数に制限がありません。任意のノートが `is_pinned: true` で作成できます。緩和策: ユーザーごとのピン付きノートの最大数をキャップする（例: 10）; 超過した場合は 422 を返してください。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|------|------|
| ATK-01 | ソフトデリートなしのハードデリート | 🚫 BLOCKED |
| ATK-02 | GET でソフトデリートされたノートにアクセス | ✅ SAFE |
| ATK-03 | 認証なしのゴミ箱パージ | ⚠️ EXPOSED |
| ATK-04 | 二重ソフトデリート | ✅ SAFE |
| ATK-05 | アクティブなノートをリストア | 🚫 BLOCKED |
| ATK-06 | タイトルへの SQL インジェクション | ✅ SAFE |
| ATK-07 | ノート ID のオーバーフロー | 🚫 BLOCKED |
| ATK-08 | ソフトデリートされたノートを更新 | 🚫 BLOCKED |
| ATK-09 | レース: リストア + パージ | 🚫 BLOCKED |
| ATK-10 | 同時ソフトデリート | ✅ SAFE |
| ATK-11 | タイトルが長すぎる | ⚠️ EXPOSED |
| ATK-12 | ピンフラッド | ⚠️ EXPOSED |

**7 BLOCKED、2 SAFE、3 EXPOSED** — 重大: パージを認証してアクターのデータにスコープする; タイトル/ボディの長さ制限を追加する; ユーザーごとのピン付きノート数をキャップする。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| 最初の DELETE でハードデリートする | 復元パスなし; 誤った削除が永続的になる |
| 一覧/取得クエリに `deleted_at IS NULL` フィルターなし | ソフトデリートされたアイテムがまだアクティブかのように再表示される |
| ソフトデリートされたノートに `PUT` を許可する | ゴーストライト — ユーザーが削除したと思っていたデータを編集する |
| `POST /trash/purge` に認証なし | 任意のクライアントがすべてのゴミ箱データを不可逆的に破壊する |
| ソフトデリートされたノートの GET に 403 を返す | ノートが存在することを明かす; 404 が存在列挙を防ぐ |
| ソフトデリート後の行数チェックなし | ノートが見つからない場合にサイレント 200; 常に影響を受けた行を確認する |
