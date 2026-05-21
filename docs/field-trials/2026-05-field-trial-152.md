# Field Trial 152 — ポイント・ロイヤルティシステム（Point/Loyalty System）

**Date**: 2026-05-21  
**App**: `pointlog`  
**Path**: `/home/xi/docker/NENE2-FT/pointlog/`  
**NENE2 version**: ^1.5  
**Release**: v1.5.86

---

## What was built

ポイント付与・消費・残高管理・admin 調整を備えたロイヤルティシステムを実装した。
reference_id による冪等トランザクションと上限制御でポイント不正操作を防ぐ。

| Endpoint | 説明 | 権限 |
|---|---|---|
| `GET /users/{userId}/points` | 残高取得 | 本人 or admin |
| `GET /users/{userId}/points/history` | 履歴取得 | 本人 or admin |
| `POST /users/{userId}/points/earn` | ポイント付与 | 本人 or admin |
| `POST /users/{userId}/points/spend` | ポイント消費 | 本人 or admin |
| `POST /users/{userId}/points/adjust` | ポイント調整 | admin のみ |

---

## Architecture decisions

### トランザクション履歴が唯一の残高源

別テーブルに残高を保持せず、最新トランザクションの `balance_after` が現在残高。
残高の一貫性がトランザクション履歴から導出できる。

### reference_id による冪等トランザクション

注文 ID 等の外部 ID を `reference_id` として使用し、同一 reference_id のトランザクションが
重複した場合は既存のトランザクションを返す（200）。
二重付与・二重消費を防ぐ最重要機能。

### 残高の多層防御

1. アプリ層: `balance < amount` チェックで事前拒否
2. DB 層: `balance_after >= 0` の CHECK 制約で最終防衛
3. `amount > 0` の CHECK 制約でゼロ・負のトランザクション防止

### MAX_EARN_PER_TRANSACTION

1 トランザクションの付与上限（10,000 pt）を設定し、
バルク不正付与攻撃に対する上限制御を実装。
admin 調整は上限を 100,000 pt に設定（合法的な大量調整に対応）。

### adjust の add/subtract 設計

admin 調整は `adjust_type: 'add'|'subtract'` で方向を指定。
amount は常に正の整数。`type` は常に `'adjust'`（direction は amount の符号でなく adjust_type で表現）。

---

## Test results

| Suite | Tests | Result |
|---|---|---|
| `PointTest.php` (SQLite) | 18 | Pass |
| `AttackTest.php` (SQLite) | 12 | Pass |
| **Total** | **30** | **Pass** |

---

## クラッカー攻撃試験（AttackTest.php）

| ID | 攻撃内容 | 結果 |
|---|---|---|
| ATK-01 | 未認証での残高取得 → 401 | Pass |
| ATK-02 | 他ユーザーの残高盗み見 → 403 | Pass |
| ATK-03 | 他ユーザーへのポイント自己付与 → 403 | Pass |
| ATK-04 | 負の amount でポイント付与 → 422 | Pass |
| ATK-05 | amount=0 での空トランザクション → 422 | Pass |
| ATK-06 | 残高超過のポイント消費（残高が負にならない）→ 422 | Pass |
| ATK-07 | 一般ユーザーによる adjust → 403 | Pass |
| ATK-08 | 超大量ポイント付与（MAX_EARN 超過）→ 422 | Pass |
| ATK-09 | reference_id 再利用ダブルクレジット → 200（冪等） | Pass |
| ATK-10 | reference_id 再利用ダブルデビット → 200（冪等） | Pass |
| ATK-11 | SQL インジェクションを含む reference_id → 正常処理 | Pass |
| ATK-12 | 浮動小数点数 amount → 422（整数のみ許可） | Pass |

全 12 件 Pass — ポイントシステムの主要な攻撃ベクターをすべて耐久確認。

---

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 学習中）

「ポイントシステムは EC サイトや航空会社のマイレージで馴染みある概念で、
作るものがイメージしやすかった。
最新トランザクションの `balance_after` を残高として使う発想は、
銀行の通帳明細（最右列が残高）と同じで直感的に理解できた。
reference_id による冪等化は『ネットワークエラーで注文が二重送信されても
ポイントが二重付与されない』という実際の問題解決として実感できた。
`balance_after >= 0` の CHECK 制約でデータベースが残高マイナスを防ぐ設計は、
アプリのバグがあっても DB が守ってくれるという安心感につながった。
ATK-06（残高超過消費）の攻撃試験は『マイナス残高になったらどうなるか』という
現実的な疑問への回答として参考になった。」

★★★★☆ — 馴染みある概念で多層防御パターンが学べる

### Persona 2 — Laravel 経験者（NENE2 初学）

「Laravel では `UserPoint::increment('balance', $amount)` 等で直接残高を更新するパターンが多いが、
NENE2 のトランザクション履歴モデルは Eloquent の `Journal Entry` パターンに近い。
イベントソーシング（FT129）との類似点も感じた。
reference_id による冪等化は Laravel の `firstOrCreate()` と同じ発想で親しみやすかった。
admin の adjust エンドポイントは Laravel の `Gate::allows('admin')` チェックと
同等の役割で、NENE2 では `X-User-Role` ヘッダーで明示的に制御する点が分かりやすい。
`balance_after` を毎回 SELECT + 計算するパターンは、
Laravel の `sum('amount')` 等の集計クエリより若干明示的だが、
最新レコードの `LIMIT 1` で高速に取得できる点が評価できる。」

★★★★☆ — 会計・トランザクションモデルへの理解が深まる

### Persona 3 — セキュリティエンジニア

「reference_id による冪等化は TOCTOU リスクを軽減するが、
MySQL での並行リクエスト（同一 reference_id の同時 POST）では競合条件が発生しうる。
`reference_id` に UNIQUE 制約を付けてデータベースレベルで防ぐか、
`DatabaseConstraintException` をキャッチして 200 を返す実装が必要。

ATK-03（他ユーザーへの自己付与）の防御は適切。
`earn` エンドポイントで `targetUserId !== actorId && !isAdmin` チェックを実施している。

`balance_after >= 0` の DB CHECK 制約は重要な最終防衛ラインだが、
SQLite では ROLLBACK が発生するため、アプリ層での事前チェックが必須。

ATK-12（浮動小数点数）の防御: `is_int($body['amount'])` により JSON の `10.5` は
float として拒否される。`10` は int として通過。適切。

今後の考慮点: ポイント有効期限（expire トランザクション）の実装時は
スキャンによる期限切れ処理が必要になる。バッチ処理での concurrent 更新に注意。」

★★★★☆ — 主要な攻撃ベクターへの耐性は確認済み

### Persona 4 — フロントエンド開発者（API 利用者）

「GET /users/{userId}/points の残高表示が 1 リクエストで完結するシンプルな設計。
トランザクション履歴（GET .../history）で balance が含まれるので、
ヘッダーの残高表示と履歴画面を同一リクエストで賄える。
earn/spend の reference_id は『注文番号』や『割引コード ID』を渡すことで、
ページリロードや Back ボタンによる二重処理を自動防止できる。
insufficient points エラーに `balance` と `required` が含まれるので、
『あと○○ポイント必要です』というメッセージ生成が容易。
ATK-09/10 の冪等化はモバイルアプリのオフライン再試行でも活用できる設計。」

★★★★☆ — ポイント UI の実装がシンプル

### Persona 5 — インフラ・DevOps エンジニア

「`point_transactions.(user_id)` インデックスで残高クエリ（ORDER BY id DESC LIMIT 1）が高速化。
高頻度アクセス時は `user_id` + `id` の複合インデックスを検討。
トランザクション件数が多くなると `getBalance()` の SELECT が遅くなるため、
スケール時は `user_points` 専用テーブル（残高キャッシュ）を追加し、
トリガー or アプリ層で同期する設計に移行を検討。
`reference_id` への UNIQUE インデックスはパフォーマンスと一意性保証のために追加推奨。
`amount > 0` と `balance_after >= 0` の CHECK 制約はデータ整合性を保証するため、
DBバックアップからのリストア時にも安全。」

★★★★☆ — 小〜中規模に十分、大規模はキャッシュ追加

### Persona 6 — プロダクトマネージャー

「ポイントシステムは EC・アプリのエンゲージメント向上に直結する機能。
earn（付与）・spend（消費）・adjust（管理者調整）の 3 種類で主要なユースケースをカバー。
reference_id による冪等化は顧客サポートでの二重付与・二重消費問題を防ぐ重要機能。
今後の拡張:
- ポイント有効期限（expire トランザクション + バッチ処理）
- ポイント付与ルール（購入金額の X%、初回登録ボーナス等）
- ポイントランク（シルバー/ゴールド/プラチナ）
- ポイント贈与（user-to-user transfer）
- ポイント一覧・集計 API（admin ダッシュボード用）
admin の adjust エンドポイントは CS 担当者が誤付与・誤消費を修正するための
重要な運用機能。audit log（FT114）との組み合わせで説明責任を担保できる。」

★★★★★ — EC・SaaS のロイヤルティ機能として即使えるレベル

---

## Howto

`docs/howto/point-loyalty-system.md`
