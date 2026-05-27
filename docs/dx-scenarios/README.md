# DX Scenario Tests — Developer Persona Series

NENE2 を使って複雑なマルチフィーチャーアプリを構築する際の開発者体験 (DX) を、
異なるスキルレベル・属性のペルソナ視点から記録・評価するシナリオテストシリーズ。

---

## 形式

各ドキュメントは **1 アプリ × 3 ペルソナ** 構成。

### アプリ要件

- **マルチフィーチャー必須**: 単一機能ではなく、複数機能が連携する中規模アプリ
- **実務に近いリアリティ**: バリデーション・エラーハンドリング・ページネーション・認証を含む

### ペルソナ定義

| カテゴリ | 説明 |
|---------|------|
| 新卒 | 卒業 1〜2 年目。学校で PHP は触れたが実務は浅い。コピペに頼りがち |
| ロースキル | 2〜5 年目。動けばいい思考。設計は不得意だがタスクは完遂できる |
| シニア | 5〜10 年目。設計力あり。他フレームワーク経験豊富。移行コストを意識する |
| ベテラン | 10 年以上。アーキテクチャ・パフォーマンス・セキュリティまで視野に入る |

> 完全初心者（プログラミング経験なし）は対象外。

### ドキュメント骨格

```markdown
# DX Scenario: [アプリ名]

## アプリ概要
[複数機能の説明]

## Persona A — [名前]（[ペルソナ種別]・[性別]・[年齢]）
### 背景
### 作業シナリオ
### ハマりポイント
### 解決策 & 感想
### DX スコア（1〜5）

## Persona B — ...

## Persona C — ...

## まとめ & NENE2 DX 評価
```

---

## 50 アプリ候補一覧

| # | アプリ名 | 主要機能 | ファイル |
|---|---------|---------|---------|
| 01 | ショッピングカート | 商品一覧・カート・購入・コンバージョン記録 | [01-shopping-cart.md](./01-shopping-cart.md) |
| 02 | 問い合わせフォーム | カスタムフィールド・受付・メール通知・管理者確認 | [02-contact-form.md](./02-contact-form.md) |
| 03 | タスク管理 + タイムトラッキング | プロジェクト・タスク・サブタスク・作業時間記録 | [03-task-tracker.md](./03-task-tracker.md) |
| 04 | ブログ + コメント + タグ | 記事投稿・コメントスレッド・タグ分類・検索 | [04-blog-comments.md](./04-blog-comments.md) |
| 05 | 採用管理 | 求人・応募・選考ステータス・メモ | [05-recruitment.md](./05-recruitment.md) |
| 06 | ホテル予約 | 部屋・空室カレンダー・予約・キャンセル | [06-hotel-booking.md](./06-hotel-booking.md) |
| 07 | 在庫管理 | 商品・倉庫・入出庫・アラート | [07-inventory.md](./07-inventory.md) |
| 08 | フラッシュセール | タイムセール・在庫ロック・購入制限・ログ | [08-flash-sale.md](./08-flash-sale.md) |
| 09 | ユーザー評価 + レビュー | 商品レビュー・評価集計・スパムフラグ | [09-review-rating.md](./09-review-rating.md) |
| 10 | ポイント + クーポン | ポイント付与・残高・クーポン適用・履歴 | [10-points-coupon.md](./10-points-coupon.md) |
| 11 | サブスクリプション管理 | プラン・契約・更新・解約・請求 | [11-subscription.md](./11-subscription.md) |
| 12 | ドキュメント共有 | ファイルアップロード・バージョン・アクセス権 | [12-document-share.md](./12-document-share.md) |
| 13 | 社内掲示板 | カテゴリ・投稿・既読管理・通知 | [13-bulletin-board.md](./13-bulletin-board.md) |
| 14 | イベント申込 | イベント・定員・申込・キャンセル待ち | [14-event-registration.md](./14-event-registration.md) |
| 15 | アンケート + 集計 | カスタム設問・回答・集計グラフ用データ | [15-survey.md](./15-survey.md) |
| 16 | QA フォーラム | 質問・回答・ベストアンサー・投票 | [16-qa-forum.md](./16-qa-forum.md) |
| 17 | 配送追跡 | 荷物・ステータス更新・通知 | [17-shipment-tracking.md](./17-shipment-tracking.md) |
| 18 | 社員ディレクトリ | 部署・社員・スキルタグ・検索 | [18-employee-directory.md](./18-employee-directory.md) |
| 19 | 読書記録 + 本棚 | 本・読書ステータス・評価・感想 | [19-bookshelf.md](./19-bookshelf.md) |
| 20 | レシピ共有 | レシピ・材料・手順・お気に入り・カテゴリ | [20-recipe-share.md](./20-recipe-share.md) |
| 21 | 日報 + 承認フロー | 日報・上司承認・フィードバック・履歴 | [21-daily-report.md](./21-daily-report.md) |
| 22 | 求人掲示板 | 求人・応募・メッセージ・ステータス | [22-job-board.md](./22-job-board.md) |
| 23 | フリーランス見積もり | 案件・見積書・承認・請求書 | [23-freelance-quote.md](./23-freelance-quote.md) |
| 24 | 健康記録 | 体重・血圧・睡眠・グラフ用統計 | [24-health-log.md](./24-health-log.md) |
| 25 | 不動産検索 | 物件・条件検索・お気に入り・問い合わせ | [25-real-estate.md](./25-real-estate.md) |
| 26 | オンライン学習 | コース・レッスン・進捗・クイズ | [26-elearning.md](./26-elearning.md) |
| 27 | メンバーシップサイト | 会員登録・コンテンツ制限・更新 | [27-membership.md](./27-membership.md) |
| 28 | カレンダー + 予定共有 | 予定・参加者・繰り返し・リマインダー | [28-calendar.md](./28-calendar.md) |
| 29 | 顧客管理 (CRM) | 顧客・商談・フォローアップ・タグ | [29-crm.md](./29-crm.md) |
| 30 | ライブチャットログ | チャンネル・メッセージ・既読・検索 | [30-chat-log.md](./30-chat-log.md) |
| 31 | プロジェクト進捗ダッシュボード | マイルストーン・タスク・バーンダウン用データ | [31-project-dashboard.md](./31-project-dashboard.md) |
| 32 | ギフトカード管理 | 発行・残高・利用・失効 | [32-gift-card.md](./32-gift-card.md) |
| 33 | 社員研修管理 | 研修プログラム・受講・試験・修了証 | [33-training.md](./33-training.md) |
| 34 | コードレビュー記録 | PR・レビューコメント・承認・マージ記録 | [34-code-review-log.md](./34-code-review-log.md) |
| 35 | ペット健康管理 | ペット・ワクチン・体重記録・診察 | [35-pet-health.md](./35-pet-health.md) |
| 36 | 旅行プランナー | 旅程・スポット・費用・共有 | [36-travel-planner.md](./36-travel-planner.md) |
| 37 | デジタル資産管理 | 画像・動画・タグ・共有リンク | [37-digital-assets.md](./37-digital-assets.md) |
| 38 | フードデリバリー注文 | レストラン・メニュー・カート・注文・配達状況 | [38-food-delivery.md](./38-food-delivery.md) |
| 39 | スポーツ大会管理 | 大会・チーム・試合・スコア・順位表 | [39-tournament.md](./39-tournament.md) |
| 40 | 寄付・クラウドファンディング | プロジェクト・目標金額・寄付・進捗 | [40-crowdfunding.md](./40-crowdfunding.md) |
| 41 | コンテンツスケジューラー | 投稿・公開予定・チャンネル・ステータス | [41-content-scheduler.md](./41-content-scheduler.md) |
| 42 | 車両管理 | 車両・整備記録・燃費・アラート | [42-vehicle-management.md](./42-vehicle-management.md) |
| 43 | 資産台帳 | 資産・減価償却・廃棄・QR コード | [43-asset-register.md](./43-asset-register.md) |
| 44 | 入退室管理 | 従業員・ゲート・入退室ログ・月次集計 | [44-access-control.md](./44-access-control.md) |
| 45 | メンターマッチング | メンター・メンティー・スキル・面談記録 | [45-mentor-match.md](./45-mentor-match.md) |
| 46 | 語学学習 | 単語・デッキ・復習スケジュール・進捗 | [46-language-learning.md](./46-language-learning.md) |
| 47 | フィットネストラッカー | エクササイズ・セット・目標・週次サマリー | [47-fitness-tracker.md](./47-fitness-tracker.md) |
| 48 | 報告書テンプレート | テンプレート・セクション・入力・PDF 用データ | [48-report-template.md](./48-report-template.md) |
| 49 | 地域コミュニティ掲示板 | 投稿・コメント・エリアフィルタ・スパム報告 | [49-community-board.md](./49-community-board.md) |
| 50 | マルチテナント SaaS 基盤 | テナント・ユーザー・プラン・API キー・監査ログ | [50-multi-tenant-saas.md](./50-multi-tenant-saas.md) |
