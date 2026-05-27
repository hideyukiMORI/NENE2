# DX Scenario 49: 地域コミュニティ掲示板

## アプリ概要

投稿・コメント・エリアフィルター・スパム報告を管理する地域コミュニティ掲示板 API。

| 機能 | エンドポイント例 |
|------|----------------|
| 投稿 | `POST /posts`（title, content, category, area_code, anonymous: bool）|
| 投稿一覧 | `GET /posts?area=13101&category=info&sort=new` |
| コメント | `POST /posts/{id}/comments`（content, anonymous）|
| いいね | `POST /posts/{id}/likes`（UNIQUE 制約で 1 人 1 回）|
| スパム報告 | `POST /posts/{id}/reports`（reason: spam/harassment/misinformation）|
| モデレーション | `GET /admin/reports?status=pending`（未処理の報告一覧）|
| エリア管理 | `GET /areas`（市区町村コード一覧・階層）|
| 通知 | `POST /subscriptions`（area_code, category, notify_on: new_post）|

ポイント: 行政区域コードによるエリアフィルタリング（階層）、匿名投稿の個人情報保護、スパム報告のワークフロー。

---

## Persona A — 松本 真理奈（新卒・女性・24 歳）

### 背景

地域活性化に興味がある社会学部出身エンジニア 1 年目。地域 SNS サービスのユーザー。

### 作業シナリオ

1. `posts(id, user_id, title, content, category, area, is_anonymous)` テーブル。
2. エリアフィルターを「東京都」→ 全区市町村を PHP で配列に持って IN 句で絞り込む。
3. 匿名投稿の実装: `is_anonymous = true` の投稿でも `user_id` を保存
   （API レスポンスで `user_id` を返してしまうバグ）。
4. スパム報告を `reports(post_id, user_id, reason)` で単純 INSERT（重複チェックなし）。
5. いいねの重複チェックを PHP で「既存いいねを SELECT してから INSERT」の 2 クエリで実装。

### ハマりポイント

- **匿名投稿の情報漏洩**: `is_anonymous = true` でも DB には `user_id` を保存するが、
  API レスポンスで `user_id` や `author_name` を含めないよう DTO で制御が必要。
- **エリア階層フィルター**: 「東京都内のすべての投稿」を取るには都道府県コードから区市町村コードを展開する必要。
- **いいねの競合**: PHP での 2 クエリ方式は同時リクエストで重複いいねが発生する可能性。

### 解決策 & 感想

DTO で匿名フラグに応じて `author` フィールドを `null` に変換。
いいねは `UNIQUE(post_id, user_id)` + `INSERT OR IGNORE` で競合安全に。

> 「匿名投稿、DB に user_id 入れておかないとモデレーションできないけど
>  API レスポンスに出したらダメ。DTO で制御するの理解できた。
>  INSERT OR IGNORE って便利。いいねの競合問題が一行で解決した。」

### DX スコア: ⭐⭐（2/5）

匿名投稿の情報漏洩バグと競合。DTO 制御と INSERT OR IGNORE で解決。

---

## Persona B — 坂口 昌弘（ロースキル・男性・48 歳）

### 背景

市役所の IT 担当 20 年。電子申請・住民向けポータルの運用経験あり。

### 作業シナリオ

1. テーブル設計:
   - `areas(id, code, name, parent_code, level: prefecture/city/ward/town)` — 行政区域マスタ
   - `posts(id, user_id, title, content, category, area_code, is_anonymous, status: active/hidden/deleted)`
   - `post_likes(post_id, user_id, liked_at)` UNIQUE(post_id, user_id)
   - `post_reports(id, post_id, reporter_id, reason, status: pending/reviewed/dismissed, reviewed_at)`
   - `push_subscriptions(user_id, area_code, category, created_at)`
2. エリア階層フィルター:
   ```sql
   WITH RECURSIVE area_tree AS (
     SELECT code FROM areas WHERE code = ?
     UNION ALL
     SELECT a.code FROM areas a JOIN area_tree at ON a.parent_code = at.code
   )
   SELECT p.* FROM posts p WHERE p.area_code IN (SELECT code FROM area_tree)
   ```
3. 匿名投稿: DTO で `is_anonymous = true` の場合 `author_id = null`、`author_name = '匿名'` に変換。
4. スパム報告: `UNIQUE(post_id, reporter_id)` で同一ユーザーの重複報告を防止。
5. モデレーション: `reports >= 3` の投稿を「要確認」として返す。

### ハマりポイント

- **行政区域コードの整合**: JIS X 0401/0402 の都道府県コード（2桁）・市区町村コード（5桁）の体系。
  コードの桁数統一と `parent_code` の NULL（都道府県の上位なし）の扱い。
- **再帰 CTE のパフォーマンス**: エリア階層が深い場合（都道府県 → 市 → 区 → 町）の再帰深度制限。
- **スパム報告の閾値**: 「何件でモデレーション通知」のビジネスロジックの場所（UseCase か DB Trigger か）。

### 解決策 & 感想

行政区域マスタはシードデータとして `database/seeds/` に用意。再帰 CTE は深度 4 以下で十分。

> 「行政区域コードって標準があるから、シードデータとして持つべきデータの良い例。
>  再帰 CTE は `hierarchical-data.md` の howto がそのまま使えた。
>  スパム報告の閾値は UseCase に書くのが正しいと判断した。DB Trigger は NENE2 では使わない。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。再帰 CTE howto の再利用と行政区域マスタのシードデータが助かった。

---

## Persona C — 野口 健太郎（ベテラン・男性・51 歳）

### 背景

地方自治体向けシステムインテグレーター 25 年。個人情報保護法・マイナンバーシステムの設計経験あり。

### 作業シナリオ

1. テーブル設計（個人情報保護対応）:
   - `users(id, hashed_identifier, created_at)` — メールアドレス等は別テーブルに分離
   - `posts` に `content_hash` を追加（改ざん検知用）
   - `moderation_log(post_id, action, moderator_id, reason, evidence_json, acted_at)` — モデレーション監査
   - `area_subscriptions` に `notification_digest: immediate/daily/weekly` — 通知頻度設定
2. 匿名投稿の設計: `posts.user_id` は保存するが、「匿名」フラグ付き投稿では
   API レスポンスの全ての識別子をマスク。モデレーターのみ閲覧可能。
3. スパム報告のワークフロー: `post_reports` の状態機械
   `pending → under_review → dismissed | action_taken`。
4. コンテンツフィルタリング: 投稿時にNGワードリスト（`banned_words` テーブル）チェック。
5. 個人情報の保持期間: `posts.expires_at` + 定期バッチ（手動 API 代替）で自動削除。

### ハマりポイント

- **匿名投稿と法執行対応**: 匿名でも「警察から開示請求があれば出せる」設計が必要。
  `user_id` は保存するが通常は見えない設計（ロール制御で解決）。
- **NGワードの部分一致**: `SELECT * FROM banned_words WHERE ? LIKE '%' || word || '%'` は遅い。
  大量投稿時のパフォーマンスと FTS5 との組み合わせ検討。
- **個人情報の保持期間ポリシー**: 削除したい（GDPR / 個人情報保護法）vs 保持すべき（証拠保全）のバランス。

### 解決策 & 感想

NGワードは PHP 配列でキャッシュして `stripos()` でフィルタリング（SQLite FTS5 との比較検討）。

> 「地域コミュニティサービスは個人情報保護と利便性のトレードオフが難しい。
>  匿名機能でも開示請求に対応できる設計はシステム要件として明記すべき。
>  NGワードフィルタリングのパフォーマンスは本番を見てから最適化する戦略。
>  NENE2 の howto に 'ロールベースの情報マスキング' パターンを書いてほしい。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。個人情報保護設計とロールベースマスキングの howto が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 松本（新卒） | △ 匿名情報漏洩バグあり | 2/5 | 匿名 DTO 制御、INSERT OR IGNORE |
| 坂口（ロースキル） | ○ 実用的完成 | 3/5 | 再帰 CTE、行政区域マスタ |
| 野口（ベテラン） | ◎ 高品質完成 | 4/5 | 個人情報保護設計、NGワードフィルタリング |

**共通のフリクション**:
1. **匿名投稿の DTO マスキング** — `is_anonymous` フラグに応じた API レスポンス制御パターン。
2. **階層データの再帰 CTE** — `hierarchical-data.md` howto の再利用（地域・組織・カテゴリで共通）。
3. **ロールベースの情報マスキング** — 管理者のみ個人情報を見られる API 設計パターン。
