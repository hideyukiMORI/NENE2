# DX Scenario 41: コンテンツスケジューラー

## アプリ概要

投稿・公開予定・チャンネル・ステータスを管理するSNS投稿スケジューラー API。

| 機能 | エンドポイント例 |
|------|----------------|
| チャンネル管理 | `POST /channels`（platform: twitter/instagram/facebook, credentials）|
| 投稿作成 | `POST /posts`（content, media_urls, tags）|
| スケジュール | `POST /posts/{id}/schedule`（channel_id, publish_at）|
| スケジュール一覧 | `GET /schedules?status=pending&from=2026-06-01` |
| 公開実行 | `POST /admin/schedules/publish-due`（予定時刻が来た投稿を公開）|
| 公開履歴 | `GET /posts/{id}/publish-history` |
| 下書き管理 | `GET /posts?status=draft` |
| キャンセル | `DELETE /schedules/{id}` |

ポイント: 複数チャンネルへの同一投稿、公開予定時刻管理、バッチ公開（手動 API での代替）。

---

## Persona A — 岡崎 葵（新卒・女性・23 geq 歳）

### 背景

コミュニティカレッジ卒でマーケター兼エンジニア。Buffer や Hootsuite の利用経験あり。

### 作業シナリオ

1. `posts(id, content, status, publish_at)` テーブルを作成（チャンネルの概念なし）。
2. 「公開予定」を `publish_at` の更新で管理。
3. `GET /schedules/pending` を「`publish_at < now()` の投稿を取得」として実装
   （未来の予定が全て「期限切れ」として返る）。
4. 複数チャンネルへの投稿概念がなく省略。
5. 「バッチ公開」を「手動で1件ずつ公開する」APIとして実装。

### ハマりポイント

- **公開予定の条件**: `publish_at <= now()` (過去 = 公開すべき) vs `publish_at > now()` (未来 = 予定) の混乱。
- **複数チャンネル**: 同一投稿を Twitter と Instagram に別々のタイミングで投稿する設計。
- **バッチ公開**: 「現在時刻以前の未公開スケジュールを全て公開する」バッチ処理。

### 解決策 & 感想

`schedules(post_id, channel_id, publish_at, status)` テーブルを追加して再設計。
公開条件は `WHERE publish_at <= datetime('now') AND status='pending'` に修正。

> 「公開予定の < と <= の違いが最初混乱した。
>  バッチ処理の代わりに手動 API って設計、NENE2 では当然なのか。
>  cron 連携の howto があれば嬉しい。」

### DX スコア: ⭐⭐（2/5）

チャンネルの多対多設計と公開条件の理解が必要。再設計で改善。

---

## Persona B — 奥村 大介（ロースキル・男性・34 geq 歳）

### 背景

デジタルマーケティング会社の IT 担当 8 年。SNS 管理ツールを業務で使用。

### 作業シナリオ

1. テーブル設計:
   - `posts(id, user_id, content, media_json, status: draft/scheduled/published/failed)`
   - `channels(id, user_id, platform, name, is_active)`
   - `schedules(id, post_id, channel_id, publish_at, status: pending/published/cancelled, published_at)`
   - `publish_logs(schedule_id, result: success/failure, error_message, attempted_at)`
2. 公開実行: `WHERE publish_at <= datetime('now') AND status='pending'` で取得して「実際の公開」は
   外部 API 呼び出しが必要（今回はモック）。
3. 失敗時のリトライ: `publish_logs` の `result='failure'` を確認して再試行。
4. `GET /schedules?status=pending&from=2026-06-01` で未来のスケジュール一覧。
5. キャンセルは `schedules.status = 'cancelled'` + `published_at IS NULL` の確認。

### ハマりポイント

- **外部 API（Twitter/Instagram）への実際の投稿**: NENE2 から外部 API を呼ぶ方法が不明。
  `GuzzleHttp` の `composer require` で解決したが統合パターンが分からなかった。
- **リトライのタイムアウト**: 何回失敗したら「永続的失敗」にするかの設計（今回は 3 回）。
- **バッチ公開の冪等性**: 同じスケジュールが 2 回公開されないように `status` チェックで防止。

### 解決策 & 感想

実用的に完成した。外部 API の統合パターンが分からなくて時間がかかった。

> 「外部 HTTP API を NENE2 から叩くパターン、
>  GuzzleHttp を使えばいいのは分かるけど
>  サービスプロバイダーへの登録方法が分からなかった。
>  HTTP クライアント統合の howto があれば嬉しい。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。外部 HTTP API 統合パターンの howto が欲しい。

---

## Persona C — 今村 智子（シニア・女性・40 geq 歳）

### 背景

マーケティングテック会社のアーキテクト 12 年。Hootsuite の内部設計を研究したことがある。

### 作業シナリオ

1. テーブル設計（分散処理を意識）:
   - `schedules` に `lock_until` カラム: バッチ処理のレース条件防止
   - `publish_attempts(schedule_id, attempted_at, result, error)` — 全試行ログ
   - `retry_policy(max_attempts, retry_interval_minutes)` — コンテナ設定
2. バッチ公開の「楽観的ロック」: `UPDATE schedules SET lock_until=datetime('now','+5 min'), status='processing' WHERE id=? AND status='pending'`
   影響行数 0 なら別プロセスが処理中。
3. 外部 API 呼び出しは `SocialMediaPublisherPort` インターフェースを定義。
   `TwitterPublisherAdapter` / `MockPublisherAdapter` を実装。
4. 最大 3 回のリトライ後に `status='failed'` + アラート記録。
5. `GET /schedules/calendar?month=2026-06` でカレンダー表示用の月次スケジュール一覧。

### ハマりポイント

- **楽観的ロックのバッチ処理**: SQLite は 1 書き込みプロセスなので実質競合しないが、
  MySQL 移行を見越してロックパターンを実装した。
- **`SocialMediaPublisherPort`**: コンテナへの登録方法を `src/DependencyInjection/` で確認。
  Mock を DI する方法を確認するのに時間がかかった。
- **公開失敗のアラート**: 「3 回失敗したらどう通知するか」の実装（今回はログのみ）。

### 解決策 & 感想

高品質で完成。楽観的ロックパターンは over-engineering かもしれないが、習慣として実装した。

> 「Port/Adapter パターンでモックと本番を切り替える方法は
>  NENE2 のサービスプロバイダーでできた。
>  でも設定の切り替え方の howto があれば速かった。
>  楽観的ロックの UPDATE パターンは数シナリオで使えるパターン。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。Port/Adapter の Mock 切り替えと楽観的ロックの howto が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 岡崎（新卒） | △ 設計再構成必要 | 2/5 | 公開条件の混乱、チャンネル多対多 |
| 奥村（ロースキル） | ○ 実用的完成 | 3/5 | 外部 HTTP API 統合パターン |
| 今村（シニア） | ◎ 高品質完成 | 4/5 | Port/Adapter Mock 切り替え、楽観的ロック |

**共通のフリクション**:
1. **外部 HTTP API 統合パターン** — GuzzleHttp + サービスプロバイダー登録 howto（多くのアプリで必要）。
2. **Port/Adapter の Mock/本番切り替え** — テスト環境と本番で実装を差し替える DI パターン。
3. **楽観的ロックの UPDATE パターン** — `WHERE ... AND status='pending'` で競合を防ぐパターン（複数シナリオで有効）。
