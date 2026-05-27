# ハウツー: API 使用量計測 & クォータ管理

> **FT リファレンス**: FT321 (`NENE2-FT/meterlog`) — ユーザーごとの日次クォータ管理、マシンキー保護による使用量記録、エンドポイントごとの内訳、IDOR 保護、残量の非負保証、24 テスト / 92 アサーション PASS。

このガイドでは、ユーザーごとの日次 API 呼び出し数を追跡し、設定可能な日次クォータを強制する使用量計測システムの構築方法を説明します。

## スキーマ

```sql
CREATE TABLE quotas (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL UNIQUE,
    daily_limit INTEGER NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE usage_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    endpoint    TEXT    NOT NULL,
    day_key     TEXT    NOT NULL,   -- 'YYYY-MM-DD'
    recorded_at TEXT    NOT NULL
);

CREATE INDEX idx_usage_user_day ON usage_events(user_id, day_key);
```

## 定数

```php
const DEFAULT_DAILY_LIMIT = 1000;  // クォータ行が存在しない場合に適用
```

## 認証モデル

```
POST /quotas               → X-Admin-Key   （クォータ設定）
POST /usage                → X-Machine-Key （サーバーサイドの使用量記録）
POST /usage/check          → X-Machine-Key （事前クォータチェック）
GET  /usage/{id}/breakdown → X-User-Id（本人）または X-Admin-Key（任意）
```

## クォータ管理（管理者）

```php
POST /quotas  X-Admin-Key: admin-secret
{"user_id": 1, "daily_limit": 500}
→ 200  {"user_id": 1, "daily_limit": 500}

// アップサート — 既存クォータの更新
POST /quotas  X-Admin-Key: admin-secret
{"user_id": 1, "daily_limit": 1000}
→ 200  {"user_id": 1, "daily_limit": 1000}

// 管理者キーなし  → 401
// 誤ったキー     → 401
// daily_limit <= 0 → 422
```

## クォータ状況

```php
GET /quotas/1
→ 200
{
  "user_id": 1,
  "daily_limit": 500,
  "used": 3,
  "remaining": 497,
  "allowed": true
}

// クォータ行がないユーザー → DEFAULT_DAILY_LIMIT が適用される
GET /quotas/99
→ 200  {"user_id": 99, "daily_limit": 1000, "used": 0, "remaining": 1000, "allowed": true}
```

`remaining = max(0, daily_limit - used)` — **絶対に負にならない**。

## 使用量の記録

各 API リクエスト成功後にサーバーサイドで呼び出されます:

```php
POST /usage  X-Machine-Key: machine-secret
{"user_id": 1, "endpoint": "GET /articles"}
→ 201
{
  "recorded": true,
  "user_id": 1,
  "endpoint": "GET /articles",
  "day_key": "2026-05-27"
}

// マシンキーなし → 401
// user_id <= 0   → 422
// 空のエンドポイント → 422
```

## 事前クォータチェック

```php
POST /usage/check  X-Machine-Key: machine-secret
{"user_id": 1}
→ 200  {"allowed": true,  "remaining": 5, "used": 0}  // クォータ内
→ 200  {"allowed": false, "remaining": 0, "used": 2}  // 枯渇
```

## 使用量内訳

```php
GET /usage/1/breakdown?date=2026-05-27  X-User-Id: 1
→ 200
{
  "user_id": 1,
  "date": "2026-05-27",
  "total": 3,
  "breakdown": [
    {"endpoint": "GET /articles", "count": 2},
    {"endpoint": "POST /articles", "count": 1}
  ]
}

// IDOR ブロック
GET /usage/1/breakdown  X-User-Id: 2        → 403
// 管理者は任意のユーザーにアクセス可能
GET /usage/1/breakdown  X-Admin-Key: admin  → 200
// 無効な日付
GET /usage/1/breakdown?date=not-a-date      → 422
```

---

## 脆弱性アセスメント

### V-01 — キーなしのクォータ管理 ✅ SAFE

**リスク**: 未認証の呼び出し元が任意のユーザーのクォータを 0 または INT_MAX に設定する。
**所見**: SAFE — `POST /quotas` は `X-Admin-Key` を必要とします。キーなしまたは誤ったキーは 401 を返します。

---

### V-02 — 管理者キーのケース/バリアントバイパス ✅ SAFE

**リスク**: 攻撃者が `ADMIN-SECRET`、`admin_secret`、`""` を試してキーチェックを迂回する。
**所見**: SAFE — 厳密な `hash_equals()` マッチ。すべてのバリアントは 401 を返します。

---

### V-03 — 正でない daily_limit ✅ SAFE

**リスク**: `daily_limit=0` または `-1` でユーザーを永久にロックアウトする。
**所見**: SAFE — `daily_limit <= 0` に対して 422。

---

### V-04 — マシンキーなしの使用量記録 ✅ SAFE

**リスク**: 外部の呼び出し元が偽の使用量を記録してクォータを枯渇させる。
**所見**: SAFE — `POST /usage` は `X-Machine-Key` を必要とします。キーなし/誤りで 401。

---

### V-05 — エンドポイントフィールドへの SQL インジェクション ✅ SAFE

**リスク**: `"'; DROP TABLE usage_events; --"` が DB を破壊する。
**所見**: SAFE — パラメータ化クエリ。インジェクションはリテラル文字列として保存されます。テーブルは生き残ります。

---

### V-06 — 使用量記録での正でない user_id ✅ SAFE

**リスク**: `user_id=0/-1` で存在しないユーザーの行を挿入する。
**所見**: SAFE — `user_id <= 0` に対して 422。

---

### V-07 — 内訳での IDOR ✅ SAFE

**リスク**: ユーザーが別ユーザーのエンドポイント使用パターンを読み取る。
**所見**: SAFE — `X-User-Id` をパス `{id}` と比較します。不一致 → 403。管理者はバイパスできます。

---

### V-08 — 内訳での無効な日付 ✅ SAFE

**リスク**: `date=` パラメーターのパストラバーサルまたは不可能な日付がクラッシュまたは SQL エラーを引き起こす。
**所見**: SAFE — `/^\d{4}-\d{2}-\d{2}$/` + `checkdate()` バリデーション。無効 → 422。

---

### V-09 — 残クォータが負になる ✅ SAFE

**リスク**: クォータ削減後に使用量がクォータを超えると、負の `remaining` がクライアントに表示される。
**所見**: SAFE — `remaining = max(0, $daily_limit - $used)`。

---

### V-10 — 空のエンドポイント文字列 ✅ SAFE

**リスク**: 空のエンドポイントで使用不能な内訳行が作成される。
**所見**: SAFE — `endpoint === ''` に対して 422。

---

### VULN まとめ

| ID | 脆弱性 | 所見 |
|----|---------------|---------|
| V-01 | キーなしのクォータ管理 | ✅ SAFE |
| V-02 | キーのケース/バリアントバイパス | ✅ SAFE |
| V-03 | 正でない daily_limit | ✅ SAFE |
| V-04 | マシンキーなしの使用量 | ✅ SAFE |
| V-05 | エンドポイントへの SQL インジェクション | ✅ SAFE |
| V-06 | 正でない user_id | ✅ SAFE |
| V-07 | 内訳での IDOR | ✅ SAFE |
| V-08 | 無効な日付フォーマット | ✅ SAFE |
| V-09 | 残クォータが負になる | ✅ SAFE |
| V-10 | 空のエンドポイント文字列 | ✅ SAFE |

**10 SAFE, 0 EXPOSED** — 重大な所見なし。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| `remaining` を負にする | 混乱を招く負の数；ゲートロジックが壊れる |
| 使用量記録にマシンキーをかけない | 任意のクライアントが他ユーザーのクォータを増減できる |
| 内訳で IDOR チェックをしない | エンドポイント使用パターンが未認可ユーザーに漏洩する |
| クォータチェック前に使用量を記録する | 拒否された呼び出しがクォータを消費する |
| `daily_limit=0` を許可する | ユーザーが最初から永久にロックアウトされる |
