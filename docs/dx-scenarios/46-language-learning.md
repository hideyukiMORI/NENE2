# DX Scenario 46: 語学学習

## アプリ概要

単語帳・デッキ・復習スケジュール・学習進捗を管理する語学学習 API。

| 機能 | エンドポイント例 |
|------|----------------|
| デッキ管理 | `POST /decks`（name, language, description）|
| 単語管理 | `POST /decks/{id}/cards`（front, back, example_sentence）|
| 復習セッション | `POST /review-sessions`（deck_id）→ 今日の復習カードを返す |
| 回答記録 | `POST /review-sessions/{id}/answers`（card_id, result: correct/incorrect, response_time_ms）|
| 進捗確認 | `GET /decks/{id}/progress`（習得率・連続学習日数・苦手カード）|
| 復習スケジュール | `GET /cards/due-today`（今日復習すべきカード一覧）|
| 苦手分析 | `GET /decks/{id}/weak-cards`（正答率が低いカード）|
| 統計ダッシュボード | `GET /stats/monthly?month=2026-05`（月別学習時間・習得数）|

ポイント: 間隔反復法（SRS: Spaced Repetition System）のスケジューリング、連続学習日数（ストリーク）の計算、正答率の集計。

---

## Persona A — 中野 優斗（新卒・男性・24 歳）

### 背景

外国語学部卒でアプリエンジニアに転向。Anki を使った自己学習経験あり。

### 作業シナリオ

1. `decks(id, name, language)` と `cards(id, deck_id, front, back)` テーブル。
2. 復習スケジュールを「最後に復習してから 1 日以上経過したカード」として単純実装
   （`WHERE last_reviewed_at < date('now', '-1 day')`）。
3. 正答率を PHP ループで `count(correct) / count(total)` として計算。
4. 連続学習日数を PHP で `$today - $lastStudied` として実装しようとして詰まる（日付計算）。
5. 「今日の復習」と「未学習カード」を区別しない設計。

### ハマりポイント

- **SRS の間隔計算**: 正解回数に応じて次回復習日が変わる（1日 → 3日 → 7日 → 14日...）。
- **連続学習日数の計算**: `date('now')` と最後の学習日の差分を日数で計算する SQL。
- **「今日復習すべき」条件**: `next_review_at <= date('now')` が基本だが、「新規カード」の初回スケジュール設定も必要。

### 解決策 & 感想

`cards` に `next_review_at` と `interval_days` と `correct_streak` を追加。
連続学習日数は `review_logs` テーブルを作って `DATE(studied_at) = date('now', '-1 day')` で前日チェック。

> 「Anki を使うのと作るのは全然違う。
>  SRS の仕組みは使ってたけど、アルゴリズムを自分で実装するのは難しかった。
>  連続学習日数は前日に学習したかどうかの再帰的なチェックが必要で複雑だった。」

### DX スコア: ⭐⭐（2/5）

SRS アルゴリズムと連続学習日数で詰まった。設計のやり直しが必要。

---

## Persona B — 伊藤 令子（ロースキル・女性・36 歳）

### 背景

語学スクール受付 → IT 担当に転向 12 年。Duolingo や Anki のヘビーユーザー。

### 作業シナリオ

1. テーブル設計:
   - `cards(id, deck_id, front, back, example, interval_days, ease_factor, next_review_at, correct_streak)`
   - `review_logs(id, card_id, user_id, reviewed_at, result: correct/incorrect, response_time_ms)`
   - `user_streaks(user_id, current_streak, longest_streak, last_studied_date)` — ストリーク管理
2. 簡易 SRS（SM-2 ライト版）:
   - 正解: `interval_days = MAX(1, ROUND(interval_days * ease_factor))` / `ease_factor += 0.1`（上限 2.5）
   - 不正解: `interval_days = 1` / `ease_factor = MAX(1.3, ease_factor - 0.2)` / `correct_streak = 0`
   - 次回: `next_review_at = date('now', '+' || interval_days || ' days')`
3. 今日の復習: `WHERE next_review_at <= date('now') AND user_id = ?`
4. 連続学習日数:
   ```sql
   SELECT CAST(julianday('now') - julianday(last_studied_date) AS INTEGER) AS days_since
   FROM user_streaks WHERE user_id = ?
   ```
   前日（days_since = 1）なら streak +1、今日（= 0）なら維持、それ以外はリセット。
5. 月次統計: `GROUP BY strftime('%Y-%m', reviewed_at)` で集計。

### ハマりポイント

- **ease_factor の SQLite REAL 型**: SM-2 の `ease_factor` を更新する際の浮動小数点精度。
  整数で保存（× 100 してから計算）するか、REAL のまま使うかの判断。
- **連続学習日数のタイムゾーン**: `date('now')` は UTC → サーバーのタイムゾーン設定確認。
- **`next_review_at` の文字列結合**: `date('now', '+' || interval_days || ' days')` が SQLite で動作するか確認。

### 解決策 & 感想

`ease_factor` は `REAL` で保存（精度は整数ほど重要でない）、`next_review_at` の動的計算は SQLite で確認済み。

> 「SM-2 アルゴリズム、論文読んで実装した。
>  ease_factor は整数での保存も検討したが、REAL で精度は十分だった。
>  `date('now', '+N days')` を N をカラム値から動的に作れるのが便利。
>  SQLite の日付関数 howto に動的 interval パターンを書いてほしい。」

### DX スコア: ⭐⭐⭐（3/5）

SRS 実装完成。SQLite 動的 interval と日付計算 howto が欲しい。

---

## Persona C — 原田 浩一（ベテラン・男性・50 歳）

### 背景

教育系 SaaS のテックリード 22 年。適応学習システム（Adaptive Learning）の設計経験あり。

### 作業シナリオ

1. テーブル設計（将来の拡張対応）:
   - `cards` に `card_type: vocabulary/grammar/listening/reading` と `difficulty_level: 1-5`
   - `review_schedules(card_id, user_id, algorithm: sm2/fsrs/leitner, next_review_at, stability, difficulty, last_review_at)` — アルゴリズム切り替え可能
   - `learning_stats(user_id, date, cards_reviewed, cards_learned, total_time_ms, streak_day)` — 日次集計テーブル
2. FSRS（Free Spaced Repetition Scheduler）の簡易実装: `stability` と `difficulty` で次回日を計算。
3. `learning_stats` を日次バッチ代替（`POST /admin/stats/daily-aggregate`）で更新。
4. 連続学習日数: `learning_stats` で `WHERE date >= date('now', '-30 days')` の連続日チェック。
5. 苦手カード分析: `GROUP BY card_id HAVING AVG(result='correct') < 0.6` で抽出。

### ハマりポイント

- **FSRS の安定性（stability）計算**: `stability = stability * exp(0.1 * (11 - difficulty) * (rating - 3))` のような計算。
  SQLite に `exp()` 数学関数がなく（バージョン依存）PHP でフォールバックが必要。
- **`learning_stats` のべき等な集計**: 同じ日に 2 回バッチが走っても重複しないよう `INSERT OR REPLACE`。
- **連続日チェックの SQL**: `learning_stats` の連続した日付を検出する再帰 CTE か GAP 検出クエリ。

### 解決策 & 感想

SQLite の数学関数不足は PHP で計算してから保存するハイブリッド方式で対応。

> 「SQLite に exp() がない（厳密にはバージョンとコンパイルオプション依存）のは痛かった。
>  数学的な計算は PHP でやって、結果だけ SQLite に保存するパターンにした。
>  FSRS は今後の主流アルゴリズムだが、複雑さに見合う実装コストは考える必要がある。
>  連続日チェックの GAP 検出 SQL は汎用的で howto 価値が高い。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。SQLite 数学関数の制限と GAP 検出 SQL パターンが課題。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 中野（新卒） | △ SRS 設計で詰まり | 2/5 | SRS アルゴリズム設計、連続学習日数 |
| 伊藤（ロースキル） | ○ 実用的完成 | 3/5 | SQLite 動的 interval、ease_factor 型 |
| 原田（ベテラン） | ◎ 高品質完成 | 4/5 | SQLite 数学関数、GAP 検出 SQL |

**共通のフリクション**:
1. **SQLite 動的 interval** — `date('now', '+' || column || ' days')` パターン（複数シナリオで登場）。
2. **連続日（ストリーク）計算** — GAP 検出 SQL または `learning_stats` 日次テーブルパターン。
3. **SQLite 数学関数の制限** — `exp()` / `sqrt()` 等のバージョン・コンパイル依存の注意事項 howto。
