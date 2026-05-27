# ハウツー: リソース予約・ブッキング API

> **FT リファレンス**: FT335 (`NENE2-FT/reservationlog`) — 半開区間の重複防止、公開レスポンスから user_id を除外、キャンセル IDOR 保護（403）、管理者/ユーザー二重レベルアクセスを備えたリソースタイムスロット予約、30 テスト / 70+ アサーション PASS。

このガイドでは、部屋/リソース予約システムの構築方法を解説します: 予約可能リソースの作成（管理者）、タイムスロットの予約（ユーザー）、重複の原子的防止、公開レスポンスでのユーザープライバシー保護。

## スキーマ

```sql
CREATE TABLE resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL REFERENCES resources(id),
    user_id     INTEGER NOT NULL,
    starts_at   TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at     TEXT    NOT NULL,
    note        TEXT,               -- オプション
    created_at  TEXT    NOT NULL
);
```

日付は ISO 8601 UTC 文字列（`2026-06-01T09:00:00Z`）として保存されます。UTC ISO 文字列の辞書順比較は正確です。

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/resources` | 管理者 | 予約可能リソースを作成する |
| `POST` | `/resources/{id}/book` | ユーザー | タイムスロットを予約する |
| `DELETE` | `/bookings/{id}` | ユーザー（オーナー） | 自分の予約をキャンセルする |
| `GET` | `/bookings` | ユーザー | 自分の予約を一覧表示する |
| `GET` | `/resources/{id}/bookings` | 管理者 | リソースのすべての予約を一覧表示する |

## リソースの作成（管理者）

```php
POST /resources
X-Admin-Key: admin-secret
{"name": "Meeting Room 1"}
→ 201  {"resource": {"id": 1, "name": "Meeting Room 1", "created_at": "..."}}

// 管理者キーなし
POST /resources  {"name": "Room"}
→ 401

POST /resources  X-Admin-Key: admin-secret  {"name": ""}
→ 422  // name required

POST /resources  X-Admin-Key: admin-secret  {"name": "x".repeat(201)}
→ 422  // name too long (max 200 chars)
```

## タイムスロットの予約

```php
POST /resources/1/book
X-User-Id: 101
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T10:00:00Z"}
→ 201
{
  "booking": {
    "id": 1,
    "resource_id": 1,
    "starts_at": "2026-06-01T09:00:00Z",
    "ends_at": "2026-06-01T10:00:00Z",
    "note": null,
    "created_at": "..."
    // user_id は返されない — IDOR 防止
  }
}

// オプションのノート
POST /resources/1/book  X-User-Id: 101
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T10:00:00Z", "note": "Team meeting"}
→ 201  {"booking": {..., "note": "Team meeting"}}
```

### バリデーションエラー

```php
// ends_at が starts_at より前
{"starts_at": "2026-06-01T10:00:00Z", "ends_at": "2026-06-01T09:00:00Z"}
→ 422

// starts_at == ends_at（ゼロ期間）
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T09:00:00Z"}
→ 422

// starts_at が欠如
{"ends_at": "2026-06-01T10:00:00Z"}
→ 422

// X-User-Id が欠如
POST /resources/1/book  (ヘッダーなし)
→ 400

// ゼロまたはオーバーフローの X-User-Id
X-User-Id: 0    → 400
X-User-Id: 9999999999999999999  → 400

// 不明なリソース
POST /resources/9999/book  X-User-Id: 101  {...}
→ 404
```

## 重複防止 — 半開区間

スロットは**半開**です: `[starts_at, ends_at)`。1 つの予約の終了と次の開始が等しくても重複しません。

```php
// 09:00–10:00 を予約
POST /resources/1/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 201

// 重複 — 既存スロット内から開始
POST /resources/1/book  {"starts_at": "10:00", "ends_at": "11:00"}  → 201  ✅ 隣接、許可

// 重複 — 内包
POST /resources/1/book  {"starts_at": "09:30", "ends_at": "11:00"}  → 409  ❌ 重複

// 同一スロット
POST /resources/1/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 409  ❌

// 同じリソースで重複なし
POST /resources/1/book  {"starts_at": "14:00", "ends_at": "15:00"}  → 201  ✅

// 別のリソースで同じスロット — 常に許可
POST /resources/2/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 201  ✅
```

### 重複 SQL クエリ

```sql
-- 競合を検出: NOT (new.ends_at <= existing.starts_at OR new.starts_at >= existing.ends_at)
SELECT COUNT(*) FROM bookings
WHERE resource_id = ?
  AND starts_at < ?   -- existing.starts_at < new.ends_at
  AND ends_at   > ?   -- existing.ends_at > new.starts_at
```

count > 0 の場合、409 Conflict を返します。

## 予約のキャンセル（IDOR 保護）

```php
DELETE /bookings/1
X-User-Id: 101
→ 200  {"cancelled": true}

// 間違ったユーザー → 403（404 ではない）
DELETE /bookings/1
X-User-Id: 102
→ 403  // ユーザー 102 は予約 1 を所有しない

// 見つからない → 404
DELETE /bookings/9999
X-User-Id: 101
→ 404
```

**間違ったユーザーのキャンセルには 403（404 ではない）を返す** — 404 を返すとユーザーが他のユーザーの予約 ID を調べられます。予約は存在しますが、リクエスターがオーナーではありません。

```php
// キャンセル後、スロットが空き
DELETE /bookings/1  X-User-Id: 101  → 200
POST /resources/1/book  X-User-Id: 102  {"starts_at": "09:00", "ends_at": "10:00"}  → 201
```

### ID バリデーション

```php
DELETE /bookings/0                    → 422  // ゼロは無効
DELETE /bookings/99999999999999999999 → 422  // オーバーフロー
POST /resources/0/book  X-User-Id: 101 {...} → 422  // resource_id ゼロは無効
```

## 自分の予約一覧（ユーザー）

```php
GET /bookings
X-User-Id: 101
→ 200
{
  "total": 2,
  "data": [
    {"id": 1, "resource_id": 1, "starts_at": "...", "ends_at": "...", "note": null, "created_at": "..."}
    // user_id は含まれない
  ]
}

// 他のユーザーの予約は返されない
// ユーザー 101 はユーザー 102 が予約を持っていても自分の予約のみ見える
```

## リソース予約一覧（管理者）

```php
GET /resources/1/bookings
X-Admin-Key: admin-secret
→ 200
{
  "total": 2,
  "data": [
    {"id": 1, "user_id": 101, "starts_at": "2026-06-01T09:00:00Z", ...},  // user_id が見える
    {"id": 2, "user_id": 102, "starts_at": "2026-06-01T14:00:00Z", ...}
  ]
}
// starts_at ASC で順序付け

GET /resources/1/bookings  (管理者キーなし)  → 401
GET /resources/9999/bookings  X-Admin-Key: key  → 404
```

管理者はレスポンスで `user_id` を受け取ります; 公開ユーザーエンドポイントは決して `user_id` を返しません。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| 重複チェック: `new.starts_at < existing.ends_at AND new.ends_at > existing.starts_at`（閉区間） | 隣接スロット（A の終了 = B の開始）が重複として誤って拒否される |
| 公開予約レスポンスで `user_id` を返す | 各予約のオーナーが誰かを公開し、ユーザー列挙が可能になる |
| 間違ったユーザーのキャンセルに 404 を返す | 攻撃者が予約の存在を確認できる; 所有権の不一致を認めるために 403 を使う |
| `starts_at >= ends_at` を受け付ける | ゼロまたは負の期間の予約が空き状況計算を破壊する |
| 重複クエリに resource_id スコープなし | リソース 1 のユーザー A の予約がリソース 2 をブロックする（偽の競合） |
| リクエストボディから `user_id` を信頼する | 攻撃者が任意のユーザーの代わりに予約を作成できる; 常に `X-User-Id` ヘッダーから ID を読み取る |
