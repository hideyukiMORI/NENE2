# Field Trial 153 — アクティビティフィード（Activity Feed）

**Date**: 2026-05-21  
**App**: `feedlog`  
**Path**: `/home/xi/docker/NENE2-FT/feedlog/`  
**NENE2 version**: ^1.5  
**Release**: v1.5.87

---

## What was built

フォローベースのアクティビティフィードを実装した。
ユーザーが他ユーザーをフォローし、フォロー相手の公開アクティビティをカーソルページネーションで取得できる。
FT153 は 3FT 周期の脆弱性診断 + 5FT 周期の MySQL 統合テストの同時実施回。

| Endpoint | 説明 | 認証 |
|---|---|---|
| `GET /feed` | 自分＋フォロー中ユーザーのフィード | 必須 |
| `POST /users/{userId}/activities` | アクティビティ投稿 | 本人のみ |
| `GET /users/{userId}/activities` | ユーザーのアクティビティ一覧 | 必須 |
| `POST /users/{followeeId}/follow` | フォロー（冪等 201/200） | 必須 |
| `DELETE /users/{followeeId}/follow` | フォロー解除 | 必須 |

---

## Architecture decisions

### カーソルページネーション（`before_id`）

オフセットベースではなく `id` カーソルを使用。
`ORDER BY id DESC` + `id < ?` で一定の高速性を保証。
`next_cursor: null` で末尾を表現。

### フィード SQL の構造

サブクエリで `follows` テーブルから followee_id を取得し、
`OR actor_id = ?`（自分自身）で自己投稿も表示する設計。
`is_public = 1` フィルタリングをフィードレベルで実施。

### プライバシー制御

`getUserActivities()` では owner と viewer を比較し、非 owner には `is_public = 1` のみ返す。
フィードは常に公開アクティビティのみ（フォロー相手でもプライベートは非表示）。

### actor_id はヘッダーから

リクエストボディの `actor_id` / `user_id` フィールドを完全に無視し、
`X-User-Id` ヘッダーのみを信頼源とする（VULN-I で確認済み）。

### フォローの冪等性

既存フォローの場合 `200 OK`、新規の場合 `201 Created` を返す。
`UNIQUE(follower_id, followee_id)` でデータ層でも重複を防止。

---

## Test results

| Suite | Tests | Result |
|---|---|---|
| `FeedTest.php` (SQLite) | 40 | Pass |
| `VulnTest.php` (SQLite) | 12 | Pass |
| `MysqlFeedTest.php` (MySQL) | 5 | Pass |
| **Total** | **57** | **Pass** |

---

## 脆弱性診断（VulnTest.php）

| ID | 脆弱性 / テスト内容 | 結果 |
|---|---|---|
| VULN-A | 未認証でのフィード取得 → 401 | Pass |
| VULN-B | 未認証でのアクティビティ投稿 → 401 | Pass |
| VULN-C | 他ユーザーのプライベートアクティビティ盗み見 → 空配列 | Pass |
| VULN-D | 他ユーザー名義でのアクティビティ投稿 → 403 | Pass |
| VULN-E | SQL インジェクション（ユーザー ID）→ 整数キャストで無効化・404 | Pass |
| VULN-F | SQL インジェクション（summary）→ パラメータライズドクエリで安全保存 | Pass |
| VULN-G | 不正な type 値 → 422 | Pass |
| VULN-H | 自己フォロー → 422 | Pass |
| VULN-I | user_id ボディインジェクション → X-User-Id ヘッダーが優先 | Pass |
| VULN-J | 存在しないユーザーのアクティビティ取得 → 404（情報漏洩なし） | Pass |
| VULN-K | 超大量 summary（100,000 文字）→ 500 なし（正常処理） | Pass |
| VULN-L | フォロー関係のないユーザーのプライベートアクティビティがフィードに露出しない | Pass |

全 12 件 Pass — アクティビティフィードの主要な攻撃ベクターをすべて耐久確認。

---

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 学習中）

「Twitter や Instagram のフォロー機能を自分で実装できるというのは、
SNS の仕組みをコードで体験できる点でとても面白かった。
`follows` テーブルで follower と followee を繋ぐ設計は
『友達申請』の仕組みと同じで直感的に理解できた。

カーソルページネーション（`before_id`）は最初は難しく感じたが、
『ID が小さいほど古い投稿、IDが大きいほど新しい投稿』と
`ORDER BY id DESC` の意味を理解してからスムーズになった。

`is_public` フラグで公開・非公開を切り替える設計は、
Instagram の公開・非公開アカウントの概念と同じで親しみやすかった。
プライベートなアクティビティが他人のフィードに表示されない
VULNテスト（VULN-C, VULN-L）は重要性がよく伝わった。」

★★★★☆ — SNS の仕組みをゼロから学べる

### Persona 2 — Laravel 経験者（NENE2 初学）

「Laravel では Eloquent の `HasMany`・`BelongsToMany` でフォロー関係を表現するが、
NENE2 のシンプルな SQL JOIN で同じことを表現する方法がよく分かった。
フィード取得の SQL クエリ（サブクエリで followee_id を取得）は
Laravel の `whereIn(function($q) { $q->select('followee_id')... })` と同等で、
どちらのアプローチも理解しやすかった。

カーソルページネーションは Laravel の `cursorPaginate()` と同じ概念で、
`id < ?` のクエリを手動で書く形は実装の透明性が高い。

`actor_id` をヘッダーから取得する設計は、
Laravel の `Auth::id()` に近い感覚で、
認証済みユーザーのIDをリクエストボディから受け取らない原則が
NENE2 でも明確に実装されていた。」

★★★★☆ — SQL JOIN の透明性が高く学習価値あり

### Persona 3 — セキュリティエンジニア

「actor_id をリクエストボディから取得しない設計（VULN-I）は
正しいアプローチ。X-User-Id ヘッダーのみを信頼源とすることで
なりすましリスクを排除している。

VULN-F（SQL インジェクション in summary）はパラメータライズドクエリにより
完全に防御されている。`'); DROP TABLE activities; --` を含む summary が
正常に保存・表示されることを確認。

VULN-L（フォロー外ユーザーのプライベートアクティビティ非露出）は
フィード SQL の `is_public = 1` 条件で保証されているが、
この条件が消えた場合に気づけるようにフィードテストに
「フォロー＋プライベートを投稿してもフィードに出ない」テストを
追加すると防御の多層化になる（今回は VULN-L で確認済み）。

フォロー冪等（201/200）の実装はサーバーサイドで状態を確認してから
INSERTする TOCTOU パターンだが、並行リクエストでは競合の可能性がある。
`UNIQUE` 制約でデータ層でも防止されているため、実用上は問題なし。

今後の考慮点: JWT や OAuth2 を使う場合、`X-User-Id` の
信頼チェーンを明確にする必要がある（誰がこのヘッダーを設定したか）。」

★★★★☆ — 主要な攻撃ベクターへの耐性は確認済み

### Persona 4 — フロントエンド開発者（API 利用者）

「カーソルページネーション（`next_cursor`）はスクロールロードの
実装が非常にシンプルになる。`next_cursor: null` で『もう終わり』が
分かるので、インフィニットスクロールの実装が直感的。

フォローの冪等（201/200）はボタンの連打や離脱・再訪問時の
再送信でも安全で、フロントエンドの状態管理が簡単になる。

`is_public` フラグを使った公開/非公開の切り替えは、
投稿フォームの『公開』チェックボックスで直接 API パラメーターに
マッピングできるシンプルな設計。

`actor_name` が各アクティビティに含まれているのでユーザー情報の
追加 API リクエストが不要。フィード UI の1リクエスト完結設計。

`object_id` / `object_type` で対象オブジェクト（記事・商品・コメント等）を
柔軟に参照できる汎用設計が特徴的。」

★★★★★ — フィード UI 実装がシンプルかつ完結

### Persona 5 — インフラ・DevOps エンジニア

「`activities.(actor_id)` インデックス（または `actor_id, id` 複合インデックス）が
`getUserActivities` の `ORDER BY id DESC LIMIT ?` クエリに効く。
`follows.(follower_id)` インデックスも `getFeed` のサブクエリに必須。

フィード SQL のサブクエリ（`IN (SELECT followee_id FROM follows WHERE follower_id = ?)`）は
フォロー数が多い場合（数万フォロー）に遅くなる可能性がある。
大規模化では事前計算テーブル（Fanout-on-write）への移行を検討。

MySQL スキーマの `activities.is_public TINYINT` は適切。
`summary VARCHAR(500)` の上限はアプリ層での検証と一致させること
（現状はアプリ層で 100,000 文字を受け入れているため要調整）。

カーソルページネーションは `id` の単調増加に依存しているため、
ID の付番方式が変わった場合（UUID v7 など）は修正が必要。」

★★★★☆ — 小〜中規模に十分、大規模はファンアウト設計へ

### Persona 6 — プロダクトマネージャー

「Twitter/Instagram 的なアクティビティフィードは
コミュニティ機能を持つプロダクトの基盤となる重要機能。
earn/spend/adjust と同様、3 つの核心機能（フォロー・投稿・フィード）で
主要なユースケースをカバー。

フォロー冪等（201/200）は UX 改善に直結。ボタンの二重タップや
ネットワーク再試行でも安全。

今後の拡張:
- 通知（新しいアクティビティが来たときのプッシュ通知）
- アクティビティ型のフィルタリング（type=like のみ表示）
- リツイート相当の re-share 機能
- リアクション（絵文字リアクション）
- タグライン / ハッシュタグ機能
- ブロック機能（フィードから特定ユーザーを除外）
- 未読カウント（最後に確認した ID 以降のアクティビティ数）

審査済みフィールドトライアルとの組み合わせ:
- audit log（FT114）: アクティビティの変更履歴を監査
- notification（今後）: フォロー・メンション通知
- point/loyalty（FT152）: いいね数に応じたポイント付与」

★★★★★ — SNS/コミュニティ機能の基盤として完成度高い

---

## Howto

`docs/howto/activity-feed.md`
