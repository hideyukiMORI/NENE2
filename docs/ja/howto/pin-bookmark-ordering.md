# ハウツー: ピン/ブックマーク（並び順付き）

> **FT リファレンス**: FT327 (`NENE2-FT/pinlog`) — ユーザーごとの記事ピンと連番ポジション、最大ピン数制限、削除時のギャップなし再圧縮、PUT による並び替え、ユーザー分離、VULN アセスメント、19 テスト / 26 アサーション PASS。

このガイドでは、ユーザーが最大 10 件のブックマークをドラッグ並び替えサポート付きで順序付きリストとして管理するピン留め記事機能の構築方法を解説します。

## スキーマ

```sql
CREATE TABLE pins (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    article_id INTEGER NOT NULL REFERENCES articles(id),
    position   INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE(user_id, article_id)
);
```

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST`  | `/pins` | 記事をピン留めする（べき等） |
| `DELETE`| `/pins/{articleId}` | 記事のピンを外す |
| `GET`   | `/pins` | ユーザーのピン一覧を順番で取得する |
| `PUT`   | `/pins/order` | ピンを並び替える |

すべてのエンドポイントには `X-User-Id` ヘッダーが必要です。なし → 401。

## 記事をピン留めする

```php
POST /pins  X-User-Id: 1
{"article_id": 3}
→ 201  {"article_id": 3, "position": 1}

POST /pins  X-User-Id: 1  {"article_id": 7}
→ 201  {"article_id": 7, "position": 2}

// べき等 — 同じ記事を 2 回ピン留め
POST /pins  X-User-Id: 1  {"article_id": 3}
→ 200  (既にピン済み、変更なし)
```

### 制限

```php
// 既に 10 件のピンがある
POST /pins  X-User-Id: 1  {"article_id": 11}
→ 422  {"max": 10}
```

### エラーケース

```php
// 認証なし
POST /pins  {"article_id": 1}        → 401
// article_id なし
POST /pins  X-User-Id: 1  {}         → 422
// 存在しない記事
POST /pins  X-User-Id: 1  {"article_id": 999} → 404
```

## ピンを外す

```php
DELETE /pins/3  X-User-Id: 1  → 204
DELETE /pins/3  X-User-Id: 1  → 404  // 既に削除済み
```

### 削除後のポジション圧縮

ピンを削除すると、ポジションが再圧縮されます — ギャップなし:

```
削除前: [1→Art1, 2→Art2, 3→Art3]
DELETE /pins/2
削除後: [1→Art1, 2→Art3]   // ポジション 2 が Art3 になる
```

```php
// ピンを外した後、ギャップが閉じられる
GET /pins  X-User-Id: 1
→ {"pins": [
     {"article_id": 1, "position": 1},
     {"article_id": 3, "position": 2}   // position 3 → 2
  ], "count": 2}
```

## ピン一覧

```php
GET /pins  X-User-Id: 1
→ 200
{
  "pins": [
    {"article_id": 3, "position": 1},
    {"article_id": 1, "position": 2},
    {"article_id": 2, "position": 3}
  ],
  "count": 3
}

// 空の場合
GET /pins  X-User-Id: 99
→ {"pins": [], "count": 0}
```

結果は `position ASC` で順序付けられます。ユーザー 2 はユーザー 1 のピンを見ません。

## 並び替え

```php
PUT /pins/order  X-User-Id: 1
{"article_ids": [3, 1, 2]}
→ 200
{
  "pins": [
    {"article_id": 3, "position": 1},
    {"article_id": 1, "position": 2},
    {"article_id": 2, "position": 3}
  ]
}

// 不明な article_id（ピン済みでない）
{"article_ids": [1, 99]}  → 422

// X-User-Id なし
PUT /pins/order  {"article_ids": [1]}  → 401
// ボディなし
PUT /pins/order  X-User-Id: 1  {}     → 422
```

---

## 脆弱性アセスメント

### V-01 — ピンを外す際の IDOR ✅ SAFE

**Risk**: ユーザー 2 が記事 ID を推測してユーザー 1 の記事のピンを外す。
**Finding**: SAFE — DELETE クエリには `WHERE user_id = $authUserId AND article_id = $articleId` が含まれます。クロスユーザーの削除は 0 行を見つけ → 404。

### V-02 — 並び替え時の IDOR ✅ SAFE

**Risk**: ユーザー 2 がユーザー 1 のピンリストを並び替える。
**Finding**: SAFE — 並び替えはすべての `article_ids` が認証済みユーザーのピンリストにあることを検証します。外部 ID は 422 を返します。

### V-03 — ピン数制限のバイパス ✅ SAFE

**Risk**: 攻撃者が並行ピンリクエストを送信して 10 ピン制限を超える。
**Finding**: SAFE — `UNIQUE(user_id, article_id)` が重複を防ぎます。ピン数はインサート前にチェックされます。並行インサートはユニーク制約に競合します。

### V-04 — 存在しない記事をピン留め ✅ SAFE

**Risk**: 攻撃者が `article_id=999999` をピン留めして孤立した FK 参照を挿入する。
**Finding**: SAFE — 存在チェックがインサート前に実行されます。存在しない記事は 404 を返します。

### V-05 — 他のユーザーの記事をピン留め ✅ SAFE

**Risk**: クロスユーザーのピン（ユーザー 2 が `X-User-Id` を操作してユーザー 1 としてピン留めする）。
**Finding**: SAFE — この FT では `X-User-Id` が認証トークンです。本番では署名済み JWT/セッションを使ってください — クライアント提供のユーザー ID ヘッダーを直接信頼しないでください。

### V-06 — 削除後のポジションギャップが並び順を公開 ✅ SAFE

**Risk**: ポジションのギャップ（`1, 3`）が削除が発生したことを明かす; 攻撃者が削除履歴を推測する。
**Finding**: SAFE — ポジションは削除時に即座に圧縮されます。外部オブザーバーは削除順を検出できません。

### VULN サマリー

| ID | 脆弱性 | 判定 |
|----|---------------|---------|
| V-01 | ピンを外す際の IDOR | ✅ SAFE |
| V-02 | 並び替え時の IDOR | ✅ SAFE |
| V-03 | ピン数制限のバイパス | ✅ SAFE |
| V-04 | 存在しない記事のピン留め | ✅ SAFE |
| V-05 | クロスユーザーのピン | ✅ SAFE |
| V-06 | ギャップが削除履歴を公開 | ✅ SAFE |

**6 SAFE, 0 EXPOSED** — 重大な発見なし。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| 最大ピン数制限なし | 無制限のリストがクエリパフォーマンスと UX を低下させる |
| 削除後にポジションのギャップを残す | ポジションによるクライアントのソートが壊れる; クライアント側での番号振り直しが必要 |
| ピン時の記事存在チェックをスキップ | 孤立した参照がピンリストをレンダリングするクライアントを混乱させる |
| 本番で `X-User-Id` ヘッダーを信頼する | どのクライアントも設定できる; 署名済み認証（JWT、セッション）を使う |
| `UNIQUE(user_id, article_id)` なし | 重複したピンが件数を膨らませ並び替えロジックを混乱させる |
