# Field Trial 154 — プロダクトレビュー・評価システム（Product Review & Rating System）

**Date**: 2026-05-21  
**App**: `reviewlog`  
**Path**: `/home/xi/docker/NENE2-FT/reviewlog/`  
**NENE2 version**: ^1.5  
**Release**: v1.5.88

---

## What was built

商品レビュー・評価システムを実装した。
1ユーザー1商品1レビューの UNIQUE 制約、評価集計（平均・星別分布）、
カーソルページネーション、所有権ガードによる CRUD を含む。

| Endpoint | 説明 | 権限 |
|---|---|---|
| `POST /products/{productId}/reviews` | レビュー投稿 | 認証必須 |
| `GET /products/{productId}/reviews` | レビュー一覧 | 認証必須 |
| `GET /products/{productId}/reviews/summary` | 平均評価・分布 | 認証必須 |
| `PUT /products/{productId}/reviews/{reviewId}` | レビュー更新 | 本人のみ |
| `DELETE /products/{productId}/reviews/{reviewId}` | レビュー削除 | 本人のみ |

---

## Architecture decisions

### UNIQUE(product_id, user_id) による重複防止

DB 層で二重レビューを防止。アプリ層での事前チェック + 409 Conflict でユーザーに明確なエラーを返す。
削除後は UNIQUE 制約が解除されるため再投稿が可能。

### rating の整数バリデーション

`is_int($body['rating'])` により JSON の浮動小数点数（`4.5`）を拒否。
CHECK 制約（1〜5）はアプリ層で事前検証し、DB 層でも保護。

### body は省略可能（null 許容）

レビュー本文は任意。空文字は `null` に正規化して保存。
空白のみの文字列も `null` として扱う。

### サマリーの avg_rating: null（0件時）

レビューが 0 件のとき `avg_rating` は `null`（0.0 ではない）。
フロントエンドが「評価なし」と「平均 0」を区別できる。

### productId のクロスチェック

PUT/DELETE では `review.product_id === URL.productId` を確認。
他商品のレビュー ID を指定した場合も 404 を返す（情報漏洩なし）。

---

## Test results

| Suite | Tests | Result |
|---|---|---|
| `ReviewTest.php` (SQLite) | 29 | Pass |
| **Total** | **29** | **Pass** |

---

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 学習中）

「Amazon や食べログのレビュー機能を自分で作れるというのは、
身近なサービスの仕組みを学べる点で非常に面白かった。
1ユーザー1商品1レビューを `UNIQUE(product_id, user_id)` で強制する設計は、
テーブル設計でビジネスルールを表現できることを実感できた。

星 1〜5 の CHECK 制約と `is_int()` バリデーションの組み合わせで
不正入力を多層防御する設計は、セキュリティの基本として理解しやすかった。

`avg_rating: null` が 0 件を表すというのは、
`0.0` と「まだ評価なし」を区別する重要な設計判断で、
UI でのメッセージ表示（『まだレビューがありません』）に直結した。」

★★★★☆ — 身近な機能で DB 設計の基礎を学べる

### Persona 2 — Laravel 経験者（NENE2 初学）

「Laravel では `User::find($id)->reviews()->create([...])` で Eloquent の
リレーションを活用するが、NENE2 の `findByProductAndUser()` + `create()` の
2ステップは SQL の動作が透明で理解しやすかった。

`UNIQUE` 制約の `DatabaseConstraintException` キャッチパターンは
FT133（ブックマーク）と同じで、Laravel の `firstOrCreate()` との対比で理解できた。

サマリー集計（`AVG(rating)`・`GROUP BY`）を別クエリで実装するパターンは、
Laravel の `reviews()->avg('rating')` と `reviews()->groupBy('rating')->count()` に対応し、
生 SQL の透明性が高い実装だと感じた。」

★★★★☆ — SQL 集計クエリのパターンが明確

### Persona 3 — セキュリティエンジニア

「`UNIQUE(product_id, user_id)` による二重レビュー防止はデータ層での保護として適切。
ただし、アプリ層の `findByProductAndUser()` チェックと DB 制約の間に
TOCTOU 競合の可能性がある。高負荷環境では `DatabaseConstraintException` を
キャッチして 409 を返す実装も検討すること。

`is_int($body['rating'])` による浮動小数点拒否は重要。JSON の `4.5` は float で
`is_int()` が false → 422 となる。`"4"` (string) も `is_int()` が false → 安全。

PUT/DELETE での `product_id` クロスチェックは IDOR 防止として正しい設計。
`review.product_id !== URL.productId` の場合に 404 を返すことで
他商品のレビュー ID の存在を露出しない。

`user_id` をヘッダーから取得し、ボディから取らない設計は一貫している。」

★★★★☆ — 基本的なセキュリティパターンは網羅

### Persona 4 — フロントエンド開発者（API 利用者）

「サマリー API（`/reviews/summary`）が1リクエストで
合計数・平均・星別分布を全部返してくれるので、
Amazon 風の評価バーの実装が1リクエストで完結する。

`avg_rating: null` vs 数値の区別で、
『まだレビューがありません』と『平均 2.3 星』を
フロントが簡単に出し分けられる。

`body` が省略可能（null許容）なので、
『一言レビューのみ』『詳細レビュー』両方のフォームパターンに対応できる。

削除後に再投稿できる設計は、気が変わったユーザーへの配慮として自然。
409（重複）のエラーで『このレビューを更新しますか？』のような
UX フローも設計しやすい。」

★★★★★ — レビュー UI の実装がシンプルかつ完結

### Persona 5 — インフラ・DevOps エンジニア

「`reviews.(product_id)` インデックスが `listByProduct` クエリのパフォーマンスに直結。
`UNIQUE(product_id, user_id)` インデックスがそのまま `listByProduct` フィルタにも使える。

サマリーの星別分布計算（5回のクエリ）はレビュー数が多い場合に N=5 回クエリが走る。
スケール時は `GROUP BY rating` 1クエリへの最適化を検討。

```sql
-- 現在: 5回クエリ
SELECT COUNT(*) FROM reviews WHERE product_id = ? AND rating = ?

-- 最適化: 1回クエリ
SELECT rating, COUNT(*) as cnt FROM reviews WHERE product_id = ? GROUP BY rating
```

カーソルページネーションは `id` 単調増加に依存しており、
大量レビューでも高速。`(product_id, id)` 複合インデックスの追加を推奨。」

★★★★☆ — 小〜中規模に十分、大規模はサマリークエリ最適化

### Persona 6 — プロダクトマネージャー

「商品レビューは EC・アプリストア・レストランサービスで必須の機能。
1ユーザー1商品1レビューという制約はビジネスルールとして正しく、
複数レビューによる評価操作を防ぐ。

削除後に再投稿できる設計は顧客サポートの観点から重要。
誤レビューの修正フローを自然に提供できる。

今後の拡張:
- レビューへの役立った投票（Helpful: yes/no）
- オーナー返信（コメント機能との連携）
- 画像添付（file-upload.md との組み合わせ）
- レビューモデレーション（report-moderation.md との連携）
- 検証済み購入フラグ（購入記録との結合）
- ネガティブレビュー通知（notification-inbox.md との連携）

admin によるレビュー削除権限は今回未実装。
モデレーション要件がある場合は FT147（コンテンツ通報）パターンを参照。」

★★★★★ — EC・レビューサイトの基盤として即使えるレベル

---

## Howto

`docs/howto/product-review-system.md`
