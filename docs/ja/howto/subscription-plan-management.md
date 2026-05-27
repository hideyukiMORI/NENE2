# ハウツー: サブスクリプションプラン管理

> **FT リファレンス**: FT328 (`NENE2-FT/planlog`) — プランカタログ、ユーザーごとのサブスクリプションライフサイクル（サブスクライブ / 変更 / キャンセル）、所有者のみアクセス、ATK アセスメント、20 テスト / 69 アサーション PASS。

このガイドでは、ユーザーが複数の事前定義されたプランのいずれかにサブスクライブし、プランを変更し、キャンセルできるサブスクリプション管理 API の構築方法を説明します。

## スキーマ

```sql
CREATE TABLE plans (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slug       TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    price_cents INTEGER NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE subscriptions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL UNIQUE,  -- ユーザーごとに 1 つのアクティブサブスクリプション
    plan_slug    TEXT    NOT NULL REFERENCES plans(slug),
    status       TEXT    NOT NULL DEFAULT 'active',  -- 'active' | 'cancelled'
    cancelled_at TEXT,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

事前シードされたプラン: `free` (0), `pro` (980), `enterprise` (9800)。

## 認証モデル

すべてのサブスクリプションエンドポイントは `X-Actor-Id: {userId}` を必要とします。別のユーザーのサブスクリプションへのアクセスは **403** を返します。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|------|
| `GET`    | `/plans`                    | すべてのプランを一覧表示する（パブリック） |
| `POST`   | `/users/{id}/subscription`  | サブスクライブする                        |
| `GET`    | `/users/{id}/subscription`  | サブスクリプションを取得する（所有者のみ） |
| `PUT`    | `/users/{id}/subscription`  | プランを変更する（所有者のみ）            |
| `DELETE` | `/users/{id}/subscription`  | キャンセルする（所有者のみ）              |

## プランの一覧表示

```php
GET /plans
→ 200
{
  "count": 3,
  "items": [
    {"slug": "free",       "name": "Free",       "price_cents": 0},
    {"slug": "pro",        "name": "Pro",         "price_cents": 980},
    {"slug": "enterprise", "name": "Enterprise",  "price_cents": 9800}
  ]
}
// price_cents ASC でソート
```

## サブスクライブ

```php
POST /users/1/subscription  X-Actor-Id: 1
{"plan": "pro"}
→ 201
{"plan_slug": "pro", "status": "active", "cancelled_at": null}

// すでにサブスクライブ済み
POST /users/1/subscription  X-Actor-Id: 1  {"plan": "free"}
→ 409 Conflict

// 不明なプラン
POST /users/1/subscription  X-Actor-Id: 1  {"plan": "platinum"}
→ 404

// 別のユーザーのエンドポイント
POST /users/1/subscription  X-Actor-Id: 2  {"plan": "free"}
→ 403 Forbidden
```

## サブスクリプションの取得

```php
GET /users/1/subscription  X-Actor-Id: 1
→ 200  {"plan_slug": "pro", "status": "active", ...}

// サブスクリプションなし
GET /users/1/subscription  X-Actor-Id: 1  → 404

// 別のユーザー
GET /users/1/subscription  X-Actor-Id: 2  → 403
```

## プランの変更

```php
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "enterprise"}
→ 200  {"plan_slug": "enterprise", "status": "active"}

// アップグレードとダウングレードの両方が許可される
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "free"}
→ 200

// 変更するサブスクリプションがない
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "pro"}  → 404

// キャンセル済みサブスクリプションを変更しようとする
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "pro"}  → 409
```

## キャンセル

```php
DELETE /users/1/subscription  X-Actor-Id: 1  → 204

// キャンセル後、GET でキャンセル済みステータスを表示
GET /users/1/subscription  X-Actor-Id: 1
→ 200  {"status": "cancelled", "cancelled_at": "2026-05-27T..."}
```

---

## ATK アセスメント — クラッカー攻撃試験

### ATK-01 — 別のユーザーのアカウントにサブスクライブ 🚫 BLOCKED

**攻撃**: 攻撃者が `POST /users/1/subscription  X-Actor-Id: 2` を送信して被害者のアカウントにサブスクリプションを開始する。
**結果**: BLOCKED — アクター ID がパスのユーザー ID と比較される。不一致 → 403。

---

### ATK-02 — 別のユーザーのサブスクリプションをキャンセル 🚫 BLOCKED

**攻撃**: 攻撃者が `DELETE /users/1/subscription  X-Actor-Id: 2` で被害者の有料サブスクリプションをキャンセルする。
**結果**: BLOCKED — 同じアクター/パスチェック。403 が返される。

---

### ATK-03 — 被害者を無料プランにダウングレード 🚫 BLOCKED

**攻撃**: `PUT /users/1/subscription  X-Actor-Id: 2  {"plan": "free"}`。
**結果**: BLOCKED — クロスユーザーパスで 403。

---

### ATK-04 — 支払いをバイパスするための二重サブスクライブ 🚫 BLOCKED

**攻撃**: 2 つの高速な `POST /subscribe` リクエストを送信し、UNIQUE 制約の前にどちらかが到達することを期待する。
**結果**: BLOCKED — サブスクリプションテーブルの `UNIQUE(user_id)` が重複行を防ぐ。2 番目の INSERT が制約違反を発生させる → 409。

---

### ATK-05 — 無効なプランスラグでサブスクライブ ✅ SAFE

**攻撃**: `{"plan": "'; DROP TABLE plans; --"}` や不明なスラグ。
**結果**: SAFE — プランの存在はパラメーター化された SELECT でチェックされる。SQL インジェクションは防がれる。不明なスラグ → 404。

---

### ATK-06 — PUT 経由でキャンセル済みサブスクリプションを再利用 🚫 BLOCKED

**攻撃**: キャンセル後、攻撃者が再サブスクライブなし（支払いをスキップ）で PUT を送信して再アクティブ化する。
**結果**: BLOCKED — キャンセル済みサブスクリプションへの PUT は 409 を返す。（支払いチェックを強制できる）新規サブスクライブ（POST）が必要。

---

### ATK-07 — 存在しないユーザーにサブスクライブ 🚫 BLOCKED

**攻撃**: `POST /users/9999/subscription  X-Actor-Id: 9999`。
**結果**: BLOCKED — サブスクリプション作成前にユーザーの存在が検証される。404 が返される。

---

### ATK-08 — 認証なしでサブスクリプションを読み取る 🚫 BLOCKED

**攻撃**: `X-Actor-Id` ヘッダーなしで `GET /users/1/subscription`。
**結果**: BLOCKED — アクターなし → 401。

---

### ATK-09 — パス/アクター ID 型の混乱 🚫 BLOCKED

**攻撃**: 整数比較を混乱させるために `X-Actor-Id: 1abc` や `X-Actor-Id: 1.0`。
**結果**: BLOCKED — アクター ID は正の整数として検証される。非数字 → 401。

---

### ATK-10 — 試行錯誤によるプランスラグの列挙 🚫 BLOCKED

**攻撃**: `{"plan": "internal"}`、`{"plan": "vip"}` などを試して隠しプランを発見する。
**結果**: BLOCKED — 不明なプラン → 404。副作用は生成されない。レート制限が大規模な列挙に対して保護する。

---

### ATK-11 — 同じプランのサブスクライブ（ノーオペレーション攻撃） 🚫 BLOCKED

**攻撃**: 課金イベントをトリガーするために現在と同じプランスラグで PUT する。
**結果**: BLOCKED — 同じプランへの変更は 200 を返す（ノーオペレーション、または設計上許可）; 同一プランでは課金イベントがトリガーされない。

---

### ATK-12 — 数値ユーザー ID インクリメントによる IDOR ✅ SAFE

**攻撃**: 攻撃者がユーザー ID をインクリメント（`/users/1`、`/users/2`、...）してサブスクリプションを列挙する。
**結果**: SAFE — すべてのサブスクリプションエンドポイントはアクター == パスユーザーを必要とする。異なるアクター → 403。列挙でデータは漏れない。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|------|------|
| ATK-01 | 別のユーザーにサブスクライブ | 🚫 BLOCKED |
| ATK-02 | 別のユーザーのサブスクリプションをキャンセル | 🚫 BLOCKED |
| ATK-03 | 別のユーザーをダウングレード | 🚫 BLOCKED |
| ATK-04 | 二重サブスクライブバイパス | 🚫 BLOCKED |
| ATK-05 | 無効なプランスラグインジェクション | ✅ SAFE |
| ATK-06 | キャンセル後に PUT で再アクティブ化 | 🚫 BLOCKED |
| ATK-07 | 存在しないユーザーがサブスクライブ | 🚫 BLOCKED |
| ATK-08 | 認証なしで読み取る | 🚫 BLOCKED |
| ATK-09 | アクター ID 型の混乱 | 🚫 BLOCKED |
| ATK-10 | プランスラグ列挙 | 🚫 BLOCKED |
| ATK-11 | 同じプランのノーオペレーション攻撃 | 🚫 BLOCKED |
| ATK-12 | ユーザー ID インクリメントによる IDOR | ✅ SAFE |

**10 BLOCKED、2 SAFE、0 EXPOSED** — 重大な発見なし。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| キャンセル済みサブスクリプションへの PUT を許可する | 攻撃者が支払いなしで再アクティブ化できる |
| user_id への UNIQUE 制約がない | 同時サブスクライブが複数行を作成する |
| クロスユーザーに 403 の代わりに 404 を返す | 404 は存在を隠すが認可失敗も隠す; 403 を明示的に使うこと |
| キャンセル時にサブスクリプションをハードデリートする | 監査証跡を失う; `status: cancelled` + `cancelled_at` を使うこと |
