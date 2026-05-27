# ハウツー: リーダーボードランキング API

> **FT リファレンス**: FT332 (`NENE2-FT/ranklog`) — ユーザーごとの個人ベスト追跡、降順ランキング、自己ランク検索、スコア削除、ATK クラッカー思考攻撃評価を含むリーダーボード、19 テスト / 50+ アサーション PASS。

このガイドでは、ユーザーごとに個人ベストのみを保存し、ランク位置を返し、自己サービスのスコア削除を可能にするマルチリーダーボードランキングシステムの構築方法を示します。

## スキーマ

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL
);

CREATE TABLE leaderboards (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE
);

CREATE TABLE scores (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    leaderboard_id INTEGER NOT NULL REFERENCES leaderboards(id),
    user_id        INTEGER NOT NULL REFERENCES users(id),
    score          INTEGER NOT NULL,
    submitted_at   TEXT    NOT NULL,
    UNIQUE(leaderboard_id, user_id)   -- ボードごとにユーザーあたり 1 つのベストスコア
);
```

`UNIQUE(leaderboard_id, user_id)` はユーザーあたり 1 エントリを強制します — 新しい送信はスコアが高い場合のみ上書きします。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/leaderboards` | リーダーボードを作成する |
| `POST` | `/leaderboards/{id}/scores` | スコアを送信する |
| `GET` | `/leaderboards/{id}/rankings` | 全ランキングを取得する（降順） |
| `GET` | `/leaderboards/{id}/rankings/me` | 自己ランクを取得する |
| `DELETE` | `/leaderboards/{id}/scores/{userId}` | 自己スコアを削除する |

## リーダーボード作成

```php
POST /leaderboards
{"name": "Global"}
→ 201  {"id": 1, "name": "Global"}

POST /leaderboards  {"name": ""}
→ 422  // name 必須
```

## スコア送信 — 個人ベストのみ

```php
// 初回送信
POST /leaderboards/1/scores
{"user_id": 1, "score": 1000}
→ 200  {"new_best": true}

// より高いスコア
POST /leaderboards/1/scores
{"user_id": 1, "score": 1200}
→ 200  {"new_best": true}

// より低いスコア — 保存値は更新されない
POST /leaderboards/1/scores
{"user_id": 1, "score": 800}
→ 200  {"new_best": false}
```

個人ベストのみ保存されます。低いスコアは承認されますが破棄されます。

```php
// 負のスコアも有効（ペナルティ、ゴルフスコアリング等）
POST /leaderboards/1/scores  {"user_id": 1, "score": -100}
→ 200  {"new_best": true}

// エラー
POST /leaderboards/1/scores  {"user_id": 9999, "score": 100}
→ 404  // 不明なユーザー

POST /leaderboards/9999/scores  {"user_id": 1, "score": 100}
→ 404  // 不明なリーダーボード

POST /leaderboards/1/scores  {"user_id": 1}
→ 422  // score フィールド欠落
```

## ランキング取得

```php
GET /leaderboards/1/rankings
→ 200
{
  "count": 3,
  "items": [
    {"rank": 1, "user_id": 2, "score": 500},
    {"rank": 2, "user_id": 3, "score": 400},
    {"rank": 3, "user_id": 1, "score": 300}
  ]
}

// 上位 N 件に制限
GET /leaderboards/1/rankings?limit=2
→ 200  {"count": 2, "items": [...]}  // 上位 2 件のみ
```

ランキングはスコア降順でソートされます。`rank` は 1 インデックスです。

### SQL

```sql
SELECT
  RANK() OVER (ORDER BY score DESC) AS rank,
  user_id,
  score
FROM scores
WHERE leaderboard_id = ?
ORDER BY score DESC
LIMIT ?
```

## 自己ランク取得

```php
GET /leaderboards/1/rankings/me
X-User-Id: 1

→ 200  {"rank": 2, "score": 300}

// まだこのリーダーボードにいない
GET /leaderboards/1/rankings/me
X-User-Id: 99
→ 404

// アクターヘッダー欠落
GET /leaderboards/1/rankings/me
→ 400
```

`X-User-Id` ヘッダーがリクエストしているユーザーを識別します。欠落または無効なヘッダー → 400。

## スコア削除

```php
DELETE /leaderboards/1/scores/1
X-User-Id: 1
→ 204  (ボディなし)

// 既に削除済み / 未送信
DELETE /leaderboards/1/scores/1
X-User-Id: 1
→ 404
```

削除後、そのユーザーの `GET /rankings/me` は 404 を返します。

---

## ATK アセスメント — クラッカー思考攻撃テスト

### ATK-01 — 他のユーザーのスコアを送信する（ボディ IDOR） ⚠️ EXPOSED

**攻撃**: 攻撃者が `{"user_id": 2, "score": 999999}` を送信して他のユーザーをリーダーボードのトップに押し上げる。
**結果**: EXPOSED — エンドポイントはアクターが一致するかを確認せずにリクエストボディの `user_id` を使用します。認可チェック（`X-User-Id == body.user_id`）でこれを防止します。競合リーダーボードでは、`user_id` を `X-User-Id` から導出し、ボディフィールドを完全に無視してください。

---

### ATK-02 — 他のユーザーのスコアを削除する（DELETE での IDOR） ✅ SAFE

**攻撃**: 攻撃者が `X-User-Id: 1` で `DELETE /leaderboards/1/scores/2` を送信して他のユーザーのスコアを消す。
**結果**: SAFE — `DELETE /scores/{userId}` はルックアップを認証済みアクターにスコープします。パスの `userId` は `X-User-Id` に対して照合されます。不一致は 404 を返します。任意のユーザースコアを削除できるのは管理者ロールのみであるべきです。

---

### ATK-03 — スコアの整数オーバーフロー 🚫 BLOCKED

**攻撃**: 攻撃者が `{"score": 9999999999999999999999}` を送信して保存された整数をオーバーフローさせる。
**結果**: BLOCKED — PHP の JSON パーサーが大きな数値を `PHP_INT_MAX`（〜9.2×10^18）にクランプします。整数型バリデーションが文字列を拒否します。SQL `INTEGER` ストレージは 64 ビット。実際にオーバーフローは実行不可能です。

---

### ATK-04 — 浮動小数点スコアインジェクション 🚫 BLOCKED

**攻撃**: 攻撃者が `{"score": 999.9}` を送信して、浮動小数点が整数スコアより上にソートされることを期待する。
**結果**: BLOCKED — スコアは厳格な整数としてバリデーションされます。`999.9` は DB に到達する前に 422 Unprocessable Entity で拒否されます。

---

### ATK-05 — スコード経由の SQL インジェクション 🚫 BLOCKED

**攻撃**: 攻撃者が `{"score": "100; DROP TABLE scores--"}` を送信してデータベースを破壊する。
**結果**: BLOCKED — スコアはまず整数バリデーションを通過する必要があります。パラメーター化クエリ（`?` プレースホルダー）が、文字列が何らかの形でバリデーションを通過したとしても DB 層でのインジェクションを防止します。

---

### ATK-06 — 他のユーザーを沈める負のスコア 🚫 BLOCKED

**攻撃**: 攻撃者が他のユーザーのために大きな負のスコアを送信して、そのユーザーを最下位に押し込む。
**結果**: BLOCKED — 個人ベストロジックは新しいスコアが**高い**場合のみ保存スコアを置き換えます。スコア 500 のユーザーに -999999 を送信すると `new_best: false` が返され、保存スコアは変わりません。ATK-01 の軽減と組み合わせることで、スコアインジェクションは完全に防止されます。

---

### ATK-07 — ランキングへの limit インジェクション 🚫 BLOCKED

**攻撃**: 攻撃者が `GET /rankings?limit=999999` を送信してリーダーボード全体を 1 リクエストでダンプする。
**結果**: BLOCKED — `limit` は `ctype_digit` でバリデーションされ `MAX_LIMIT`（例: 100）に制限されます。制限を超えるリクエスト → 422。

---

### ATK-08 — 認証済みエンドポイントで X-User-Id が欠落 🚫 BLOCKED

**攻撃**: 攻撃者が `GET /rankings/me` や `DELETE` で `X-User-Id` を省略してアクターバリデーションをバイパスする。
**結果**: BLOCKED — 両エンドポイントは `X-User-Id` が欠落または空白の場合 400 を返します。

---

### ATK-09 — 非整数の X-User-Id ヘッダーインジェクション 🚫 BLOCKED

**攻撃**: 攻撃者がヘッダー経由で SQL をインジェクションするために `X-User-Id: 1 OR 1=1` を送信する。
**結果**: BLOCKED — `X-User-Id` は `ctype_digit` でバリデーションされます。非数字文字 → 400。値は整数バリデーションを通過せずに SQL に到達しません。

---

### ATK-10 — 存在しないリーダーボードへのスコア 🚫 BLOCKED

**攻撃**: 攻撃者がリーダーボードレベルのコントロールをバイパスするために `leaderboard_id = 9999` を作り出す。
**結果**: BLOCKED — リーダーボードの存在はスコア挿入前にチェックされます。不明なリーダーボード → 404。

---

### ATK-11 — 削除後に低スコアを再送信 🚫 BLOCKED

**攻撃**: 攻撃者がスコアを削除してから、個人ベストガードをリセットするために水増しした値を再送信する。
**結果**: BLOCKED — 削除後、行は削除されます。次の送信は新しいエントリ（`new_best: true`）です。これは期待された動作です。履歴の不変性が必要な場合は、ソフトデリート（`deleted_at`）を使用して以前のベストを保持し、再送信をブロックしてください。

---

### ATK-12 — 並行スコア送信（競合状態） 🚫 BLOCKED

**攻撃**: 2 つのリクエストがどちらかがコミットする前に同じユーザーのスコアを同時に送信する。
**結果**: BLOCKED — `UNIQUE(leaderboard_id, user_id)` とアトミックな `INSERT OR REPLACE` / `UPDATE WHERE score < new_score` により、DB レベルで 1 つのみが勝者になります。SQLite は書き込みをシリアライズします。MySQL/PostgreSQL は行レベルロックを使用します。

---

### ATK サマリー

| ID | 攻撃 | 結果 |
|----|------|------|
| ATK-01 | 他のユーザーのスコアを送信（ボディ IDOR） | ⚠️ EXPOSED |
| ATK-02 | 他のユーザーのスコアを削除 | ✅ SAFE |
| ATK-03 | スコアの整数オーバーフロー | 🚫 BLOCKED |
| ATK-04 | 浮動小数点スコアインジェクション | 🚫 BLOCKED |
| ATK-05 | スコード経由の SQL インジェクション | 🚫 BLOCKED |
| ATK-06 | 他のユーザーを沈める負のスコア | 🚫 BLOCKED |
| ATK-07 | ランキングへの limit インジェクション | 🚫 BLOCKED |
| ATK-08 | アクターヘッダー欠落 | 🚫 BLOCKED |
| ATK-09 | 非整数の X-User-Id ヘッダーインジェクション | 🚫 BLOCKED |
| ATK-10 | 存在しないリーダーボードへのスコア | 🚫 BLOCKED |
| ATK-11 | 削除後にスコアを再送信 | 🚫 BLOCKED |
| ATK-12 | 並行スコア更新の競合 | 🚫 BLOCKED |

**10 BLOCKED、1 SAFE、1 EXPOSED** — スコア送信はアクターが `user_id` と一致することを確認する必要があります。ユーザーアイデンティティを `X-User-Id` から導出し、リクエストボディの `user_id` を決して受け入れないでください。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| アクターチェックなしでリクエストボディの `user_id` を信頼する | 任意のユーザーが他のユーザーのためにスコアを送信できる |
| 個人ベストのみでなく全送信を保存する | DB が無制限に成長し、ランキングが曖昧になる |
| 浮動小数点スコアを許可する | SQL での浮動小数点比較が予期しないソート順を生む |
| `UNIQUE(leaderboard_id, user_id)` 制約なし | 重複行がユーザーの見かけのランクを膨らませる |
| 不明なリーダーボードに空リストで 200 を返す | 設定ミスを隠す; 不明なリソースには 404 |
| `/rankings?limit=` に上限なし | 大きなリーダーボードでのフルテーブルスキャンが DoS を引き起こす |
