# ハウツー: 評価・レビュー API

> **FT リファレンス**: FT333 (`NENE2-FT/ratinglog`) — アイテムごと・ユーザーごとの評価システム: スコアバリデーション（1〜5）、upsert セマンティクス、分布内訳付きサマリー、脆弱性アセスメント、16 テスト / 40+ アサーション PASS。

このガイドでは、ユーザーがオプションのテストレビューと共に数値スコアを送信し、API がリアルタイムの集計サマリーを計算する評価システムの構築方法を解説します。

## スキーマ

```sql
CREATE TABLE ratings (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id    TEXT    NOT NULL,
    rater_id   TEXT    NOT NULL,
    score      INTEGER NOT NULL CHECK (score BETWEEN 1 AND 5),
    review     TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(item_id, rater_id)
);
```

`UNIQUE(item_id, rater_id)` はアイテムごとに評価者が 1 件のみの評価を強制します。`item_id` と `rater_id` は不透明な文字列識別子です — 外部キー制約は不要です。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `PUT` | `/items/{itemId}/ratings/{raterId}` | 評価を作成または更新する（upsert） |
| `GET` | `/items/{itemId}/ratings` | アイテムのすべての評価を一覧表示する |
| `GET` | `/items/{itemId}/ratings/summary` | 分布付きの集計サマリー |
| `GET` | `/items/{itemId}/ratings/{raterId}` | 1 人の評価者の評価を取得する |
| `DELETE` | `/items/{itemId}/ratings/{raterId}` | 評価を削除する |

## 評価の作成 / 更新（Upsert）

```php
PUT /items/product-1/ratings/alice
{"score": 5, "review": "Excellent!"}
→ 200  {"rater_id": "alice", "score": 5, "review": "Excellent!", ...}

// 既存の評価を更新
PUT /items/product-1/ratings/alice
{"score": 3, "review": "Changed my mind."}
→ 200  {"score": 3}
```

`UNIQUE(item_id, rater_id)` 付きの `PUT` は自然な upsert（`INSERT OR REPLACE`）として機能します。同じエンドポイントが別の `PATCH` なしに作成と更新の両方を処理します。

### バリデーション

```php
// スコアなし
PUT /items/product-1/ratings/alice  {"review": "Nice"}
→ 422

// 範囲外
PUT /items/product-1/ratings/alice  {"score": 6}
→ 422

PUT /items/product-1/ratings/alice  {"score": 0}
→ 422
```

スコアは [1, 5] の整数でなければなりません。`review` はオプションです（デフォルト `""`）。

## 評価一覧

```php
GET /items/product-1/ratings
→ 200
{
  "ratings": [
    {"rater_id": "alice", "score": 5, "review": "Excellent!"},
    {"rater_id": "bob",   "score": 3, "review": ""}
  ]
}
```

評価はアイテムにスコープされています — `product-2` の評価は `product-1` の一覧には表示されません。

## 分布付きサマリー

```php
GET /items/product-1/ratings/summary
→ 200
{
  "count": 3,
  "average": 4.0,
  "distribution": {
    "1": 0, "2": 0, "3": 1, "4": 1, "5": 1
  }
}

// まだ評価なし
GET /items/product-2/ratings/summary
→ 200  {"count": 0, "average": 0.0, "distribution": {"1":0,"2":0,"3":0,"4":0,"5":0}}
```

`distribution` はカウントがゼロの場合でも常に 5 つのキーすべてを返します — クライアントは null チェックなしにスターバーをレンダリングできます。

## 個別評価の取得

```php
GET /items/product-1/ratings/alice
→ 200  {"score": 4, "review": "..."}

GET /items/product-1/ratings/nobody
→ 404
```

## 評価の削除

```php
DELETE /items/product-1/ratings/alice
→ 200  {"deleted": true}

DELETE /items/product-1/ratings/nobody
→ 404
```

削除後、サマリーは次のリクエスト時に即座に再計算されます。

```php
// 削除前: alice(5) + bob(1)、average=3.0
DELETE /items/product-1/ratings/bob

// 削除後: alice(5) のみ
GET /items/product-1/ratings/summary
→ 200  {"count": 1, "average": 5.0}
```

---

## 脆弱性アセスメント

### V-01 — 評価なりすまし（raterId に対する IDOR）⚠️ EXPOSED

**Risk**: 任意のクライアントが任意の `raterId` パスセグメントを使用して評価を送信または削除できる。
**Finding**: EXPOSED — URL の `raterId` は認証済みアクターに対して検証されていません。攻撃者が `raterId: "competitor"` として 1 星レビューを POST したり、別のユーザーのレビューを削除したりできます。緩和策: 評価者を認証（セッション、JWT、または `X-User-Id` ヘッダー）し、認証済みアイデンティティがパスの `raterId` と一致しないリクエストを拒否してください。

---

### V-02 — スコア範囲バイパス 🛡️ SAFE

**Risk**: 攻撃者が `score: 0` または `score: 6` を送信して無効なデータを生成したり、平均を歪めたりする。
**Finding**: SAFE — スコアは DB 書き込み前に `[1, 5]` に検証されます。範囲外の値は 422 を返します。DB レベルの `CHECK (score BETWEEN 1 AND 5)` がセカンダリガードを提供します。

---

### V-03 — 大量偽評価による平均ポイズニング ⚠️ EXPOSED

**Risk**: 攻撃者が何千ものユーザー ID を登録して商品の平均を引き下げるために 1 星評価を送信する。
**Finding**: EXPOSED — 評価エンドポイントにレート制限やアカウント確認が強制されていません。緩和策: 評価前にアカウント年齢 / メール確認を要求する; IP ごとおよびユーザーごとのレート制限を適用する; 統計的異常を検出する（低スコアの突然のバースト）。

---

### V-04 — レビューテキスト経由の XSS ✅ SAFE

**Risk**: 攻撃者が `review` に `<script>alert(1)</script>` を保存してレビューを HTML としてレンダリングするクライアントで JavaScript を実行する。
**Finding**: SAFE — API は `application/json` を返します。JSON エンコードが HTML 特殊文字（`<`、`>`、`&`）をエスケープします。クライアントが JSON 値をテキストとして（`innerHTML` ではなく）解析してレンダリングする限り、保存された XSS が防止されます。追加の層としてサーバーサイドの HTML エンコードが推奨されます。

---

### V-05 — itemId / raterId 経由の SQL インジェクション 🛡️ SAFE

**Risk**: 攻撃者が `item_id = "x' OR '1'='1"` または `rater_id = "'; DROP TABLE ratings--"` を送信してクエリを操作する。
**Finding**: SAFE — すべてのクエリはパラメーター化ステートメント（`?` プレースホルダー）を使用します。パスセグメントはバインド値として渡され、SQL 文字列に補間されません。

---

### V-06 — 無制限のレビューテキスト（ストレージ悪用）⚠️ EXPOSED

**Risk**: 攻撃者が 100 MB のレビュー文字列を送信してデータベース/メモリリソースを枯渇させる。
**Finding**: EXPOSED — `review` に `max_length` チェックが強制されていません。緩和策: `MAX_REVIEW_LENGTH` 定数（例: 2000 文字）を追加して超過した場合は 422 を返してください。リクエストサイズミドルウェアがセカンダリガードを提供します。

---

### V-07 — サマリー平均の整数切り捨て 🛡️ SAFE

**Risk**: 3 つの評価を平均（5+3+4=12、12/3=4.0）すると一部の DB エンジンで精度が失われる。
**Finding**: SAFE — SQLite の `AVG()` は float を返します。PHP がエンコード前に結果を `float` にキャストします。`(int)(5+3)/2` スタイルの切り捨ては使用されません。

---

### V-08 — 分布のキー欠如（クライアントクラッシュ）🛡️ SAFE

**Risk**: `distribution` がゼロ評価のスコアのキーを省略する場合、`distribution[1]` にアクセスするクライアントが `undefined` でクラッシュする。
**Finding**: SAFE — API は常に 5 つのキー（`1`〜`5`）をすべて `0` で初期化して返します。クライアントは防御的な null チェックを必要としません。

---

### V-09 — クロスアイテムデータ漏洩 🛡️ SAFE

**Risk**: `GET /items/product-1/ratings` が `product-2` の評価を返す。
**Finding**: SAFE — すべてのクエリに `WHERE item_id = ?` が含まれます。分離テストが `product-2` の評価が `product-1` の一覧に表示されないことを明示的に検証します。

---

### V-10 — 整数バリデーションをバイパスするための浮動小数点スコア 🛡️ SAFE

**Risk**: 攻撃者が `score: 4.9`（5 に丸め）または `score: 5.1`（5 または 6 に丸め）を送信して範囲チェックをバイパスする。
**Finding**: SAFE — スコアは厳密な整数として検証されます。JSON の float は型バリデーションに失敗し、範囲チェックの前に 422 を返します。

---

### VULN サマリー

| ID | 脆弱性 | 判定 |
|----|---------------|---------|
| V-01 | 評価なりすまし（raterId に対する IDOR） | ⚠️ EXPOSED |
| V-02 | スコア範囲バイパス | 🛡️ SAFE |
| V-03 | 大量偽評価による平均ポイズニング | ⚠️ EXPOSED |
| V-04 | レビューテキスト経由の XSS | ✅ SAFE |
| V-05 | itemId / raterId 経由の SQL インジェクション | 🛡️ SAFE |
| V-06 | 無制限のレビューテキスト（ストレージ悪用） | ⚠️ EXPOSED |
| V-07 | サマリー平均の整数切り捨て | 🛡️ SAFE |
| V-08 | 分布のキー欠如 | 🛡️ SAFE |
| V-09 | クロスアイテムデータ漏洩 | 🛡️ SAFE |
| V-10 | 整数バリデーションをバイパスする浮動小数点スコア | 🛡️ SAFE |

**7 SAFE, 3 EXPOSED** — 重大: `raterId` を認証する; `review` の長さ上限を追加する; 大量偽評価に対してレート制限を適用する。

---

## してはいけないこと

| アンチパターン | リスク |
|---|---|
| 認証なしでパスから `raterId` を信頼する | 任意のクライアントが任意のユーザーとして評価または削除できる |
| レビューテキストに `max_length` なし | ストレージ爆弾 — 単一リクエストで DB にギガバイトを書き込む |
| ゼロカウントの分布キーに `null` を返す | `distribution[2]` にアクセスするクライアントコードがクラッシュする |
| `array_sum` で PHP 内で平均を再計算する | 大きなデータセットでの損失のある浮動小数点演算; DB に `AVG()` を任せる |
| ユーザーごとのレート制限なし | 大量の偽アカウントが商品の平均を汚染する |
| `WHERE item_id` なしで `SELECT * FROM ratings` を使用する | クロスアイテムデータ漏洩 |
