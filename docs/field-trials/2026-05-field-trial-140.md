# Field Trial 140 — フラッシュセール（限定数量タイムセール）

**Date**: 2026-05-21  
**App**: `salelog`  
**Path**: `/home/xi/docker/NENE2-FT/salelog/`  
**NENE2 version**: ^1.5  
**Release**: v1.5.74  
**Special**: クラッカー攻撃試験（4FT ごと）

---

## What was built

セール期間と限定個数を持つフラッシュセールイベントを実装した。

| Endpoint | 説明 |
|---|---|
| `POST /products` | 商品作成 |
| `POST /sales` | セール作成（期間・価格・数量） |
| `GET /sales/{saleId}` | セール詳細（残数・ステータス付き） |
| `POST /sales/{saleId}/purchase` | 購入（期間チェック・在庫チェック・二重購入防止） |
| `GET /sales/{saleId}/purchases` | 購入者一覧 |

---

## Architecture decisions

### 残数は purchases テーブルの COUNT(*) から導出

`flash_sales.remaining` のようなミュータブルカラムを持つ代わりに、`purchases` テーブルの COUNT を読み取り時に計算する。更新の競合を避けられ、集計が常に正確。

```sql
SELECT COUNT(*) as cnt FROM purchases WHERE sale_id = ?
```

`remaining = quantity - count`。表示は `max(0, remaining)` でクランプ。

### UNIQUE (sale_id, user_id) で二重購入防止

`DatabaseConstraintException` を catch して 409 を返す。アプリ層の二重チェックだけでなく DB レベルで保証。

### ISO 8601 文字列での時間比較

`starts_at` / `ends_at` を `date('c')` で保存。ISO 8601 は辞書順と時系列が一致するため、文字列比較で `$now < $sale['starts_at']` が正しく動く。

### match expression でステータス計算

```php
$status = match (true) {
    $now < $sale['starts_at'] => 'upcoming',
    $now > $sale['ends_at']   => 'ended',
    default                   => 'active',
};
```

`upcoming` / `active` / `ended` の3状態をシンプルに表現。

---

## Test results

| Suite | Tests | Result |
|---|---|---|
| `SaleTest.php` | 17 | Pass |
| `AttackTest.php` | 12 | Pass |
| **Total** | **29** | **Pass** |

---

## Cracker attack test results (FT140)

| ID | 攻撃内容 | 期待値 | 結果 |
|---|---|---|---|
| ATK-01 | SQL インジェクション in 商品名 | 201（verbatim 保存） | Pass |
| ATK-02 | X-User-Id なしで購入 | 400 | Pass |
| ATK-03 | 非数値の X-User-Id | not 201 | Pass |
| ATK-04 | 負の saleId | not 201 | Pass |
| ATK-05 | セール開始前に購入 | 422 | Pass |
| ATK-06 | セール終了後に購入 | 422 | Pass |
| ATK-07 | 同一セールを二重購入 | 409 (2回目) | Pass |
| ATK-08 | 在庫を枯渇させてから購入 | 422 sold out | Pass |
| ATK-09 | quantity=0 でセール作成 | 422 | Pass |
| ATK-10 | 負の price でセール作成 | 422 | Pass |
| ATK-11 | 存在しないユーザーで購入 | 404 | Pass |
| ATK-12 | ends_at < starts_at（時間逆転） | 422 | Pass |

**全 12 件 Pass。攻撃全耐久。**

---

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 学習中）

「フラッシュセールという概念は理解しやすかった。時間のチェックが文字列比較でできるというのは知らなかった。ISO 8601 が辞書順と時系列が一致するという説明を読んで納得した。残数を COUNT(*) で計算するのは少し驚いたが、ミュータブルカラムの危険性の説明でわかった。match 式が初めて出てきたが、if-elseif の代わりに使えるとわかってスッキリした。」

★★★★☆ — 新しい概念の説明が適切で学習コストが低い

### Persona 2 — Laravel 経験者（NENE2 初学）

「Laravel なら `now()->between($sale->starts_at, $sale->ends_at)` で期間チェックできる。NENE2 では文字列比較だが ISO 8601 の性質を利用しているので実用上問題ない。Eloquent の withCount より生の COUNT(*) の方が直観的でデバッグしやすい。`DatabaseConstraintException` の使い方が FT136 から一貫しているので、パターンとして覚えやすい。」

★★★★☆ — LaravelのAPIとの違いが明確で理解しやすい

### Persona 3 — セキュリティエンジニア

「12 件の攻撃テスト全 Pass は良い。二重購入防止が DB UNIQUE 制約とアプリ層の両方でカバーされているのは堅牢。ただし在庫チェック（countPurchases）と実際の INSERT の間に TOCTOU レースがある。本番では `SELECT ... FOR UPDATE` かトランザクション内での INSERT と COUNT の同時実行が必要。X-User-Id による認証は FT スコープでは許容だが、本番では JWT/セッションが必須。ATK-04（負の saleId）が 404 を返すのは情報漏洩防止として正しい設計。」

★★★★☆ — FT 実装として合格。本番化には TOCTOU 対策が必要

### Persona 4 — フロントエンド開発者（API 利用者）

「`GET /sales/{saleId}` が `remaining` と `status` を返してくれるので、フロントで計算不要。`status: 'upcoming'|'active'|'ended'` の3値は UI 状態管理で使いやすい。購入時の 409「already purchased」と 422「sold out」が分かれているので、エラーメッセージを適切に表示できる。`POST /sales/{saleId}/purchases` ではなく `POST /sales/{saleId}/purchase`（単数）なのは RESTful として自然。」

★★★★★ — API 設計がフロント視点で使いやすい

### Persona 5 — インフラ・DevOps エンジニア

「COUNT(*) ベースの残数計算は `purchases` テーブルが大きくなるとパフォーマンス懸念がある。本番では `sale_id` に INDEX を貼ることで緩和できる。SQLite はテストで十分だが、フラッシュセールの同時購入では MySQL/PostgreSQL の行ロックが必要。テストが 29 件で期間内・外・在庫枯渇など主要シナリオをカバーしているのは良い。`AttackTest.php` が正常系と分離されているのでメンテナンスしやすい。」

★★★★☆ — テストの構造が良い。本番規模ではインデックスと DB ロックが必要

### Persona 6 — プロダクトマネージャー

「フラッシュセールは多くの EC サイトで使われる機能。一人一購入（UNIQUE制約）は公平性を保証する。在庫が `sold out` になったとき 422 で明示するのは UX として重要。セール状態が `upcoming/active/ended` の 3 状態で管理されているのは管理画面の構築にも使いやすい。今後の拡張として、複数購入許可（quantity per user）・クーポン適用・ウィッシュリスト通知などが考えられる。」

★★★★☆ — プロダクト要件を満たした実装。拡張性が高い設計

---

## Howto

`docs/howto/flash-sale-system.md`
