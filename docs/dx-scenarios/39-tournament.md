# DX Scenario 39: スポーツ大会管理

## アプリ概要

大会・チーム・試合・スコア・順位表を管理するスポーツトーナメント API。

| 機能 | エンドポイント例 |
|------|----------------|
| 大会管理 | `POST /tournaments`（name, sport, format: round_robin/single_elimination）|
| チーム登録 | `POST /tournaments/{id}/teams`（name, members）|
| 試合スケジュール | `POST /tournaments/{id}/matches`（team1_id, team2_id, scheduled_at）|
| スコア記録 | `POST /matches/{id}/result`（team1_score, team2_score）|
| 順位表 | `GET /tournaments/{id}/standings`（勝率・得失点差・ポイント）|
| トーナメント表 | `GET /tournaments/{id}/bracket`（単一敗退の対戦構造）|
| 統計 | `GET /tournaments/{id}/stats`（最高得点者・最多得点チーム）|

ポイント: リーグ戦の順位計算（ポイント制）、トーナメント表（ブラケット）、勝敗からの自動更新。

---

## Persona A — 三浦 康太（新卒・男性・22 歳）

### 背景

スポーツ科学部卒でエンジニアに転向。サッカーサークルの大会で手動集計を経験。

### 作業シナリオ

1. `tournaments` / `teams` / `matches` / `match_results` テーブルを作成。
2. 順位表計算を PHP で全試合ループして集計（遅い可能性あり）。
3. 勝ちポイント計算: 勝利=3、引き分け=1、敗北=0 をハードコード（設定不可）。
4. 単一敗退のブラケット生成を「試合順に並べる」だけで、ブラケット構造を持たせない。
5. 得失点差の計算を PHP で実装（`array_sum()` でループ）。

### ハマりポイント

- **順位計算の SQL**: 複雑な集計クエリを書けず PHP ループに逃げる。
- **ブラケット構造**: 「準決勝の勝者が決勝に進む」という構造をどう DB で表現するか不明。
- **ポイントのハードコード**: 大会によってポイントルールが違う場合の柔軟性なし。

### 解決策 & 感想

順位表 SQL を `CASE WHEN` で書き直した。ブラケット構造は「次の試合への FK」方式に変更。

> 「スポーツの順位計算ってこんなに複雑なの。
>  CASE WHEN を使う SQL の howto があれば速かった。
>  ブラケット構造は next_match_id FK にしたけどこれで正しいのか不安。」

### DX スコア: ⭐⭐⭐（3/5）

基本実装できた。スポーツ順位計算 SQL パターンと CASE WHEN howto が欲しい。

---

## Persona B — 中野 里奈（ロースキル・女性・31 geq 歳）

### 背景

スポーツジムの IT 担当 8 年。各種大会の運営サポート経験あり。

### 作業シナリオ

1. テーブル設計:
   - `tournament_teams(tournament_id, team_id, group_name)` — グループステージ対応
   - `matches(id, tournament_id, team1_id, team2_id, round, scheduled_at, played_at, next_match_id)`
   - `match_results(match_id, team1_score, team2_score, winner_id, is_draw)`
2. 順位表 SQL（リーグ戦）:
   ```sql
   SELECT t.id, t.name,
     SUM(CASE WHEN mr.winner_id = tt.team_id THEN 3 WHEN mr.is_draw THEN 1 ELSE 0 END) AS points,
     SUM(CASE WHEN mr.winner_id = tt.team_id THEN 1 ELSE 0 END) AS wins,
     SUM(CASE WHEN mr.is_draw THEN 1 ELSE 0 END) AS draws,
     SUM(CASE WHEN mr.winner_id != tt.team_id AND NOT mr.is_draw THEN 1 ELSE 0 END) AS losses
   FROM tournament_teams tt
   JOIN teams t ON t.id = tt.team_id
   LEFT JOIN matches m ON ...
   LEFT JOIN match_results mr ON mr.match_id = m.id
   WHERE tt.tournament_id = ?
   GROUP BY tt.team_id
   ORDER BY points DESC, wins DESC
   ```
3. ブラケット: `matches.next_match_id` で次の試合への参照。試合結果後に `winner` を次の試合の `team1/team2` に更新。
4. `game-score-leaderboard-api.md` のパターンが参考になった（スコアランキング）。

### ハマりポイント

- **順位表の複雑な CASE WHEN**: 試合に両チームが絡む（home/away）関係でクエリが複雑。
- **引き分けの扱い**: `is_draw = true` の場合の点数計算を正しく実装するのに時間がかかった。
- **ブラケットの自動更新**: 試合結果登録時に `next_match` の出場チームを自動更新する処理が難しかった。

### 解決策 & 感想

`game-score-leaderboard-api.md` が参考になった。ブラケット自動更新は UseCase に実装。

> 「game-score-leaderboard howto は参考になった。
>  でも大会の順位計算は特有の複雑さがある。
>  ブラケットの自動更新ロジックは難しかった。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。ブラケット管理と複雑な CASE WHEN SQL のパターン howto が欲しい。

---

## Persona C — 小松 雅也（ベテラン・男性・44 geq 歳）

### 背景

Esports プラットフォーム開発 15 年。トーナメント管理システムの設計を多数手がけた。

### 作業シナリオ

1. テーブル設計（汎用トーナメントエンジン）:
   - `tournaments(format: round_robin|single_elimination|double_elimination, point_win, point_draw, point_loss)`
   - `tournament_rounds(tournament_id, round_number, name)` — ラウンド定義
   - `matches(id, round_id, team1_id, team2_id, next_match_id, is_lower_bracket)` — ダブルイリミ対応
   - `match_results(match_id, team1_score, team2_score)` — 正規化されたスコア
   - `standings_snapshot(tournament_id, team_id, points, wins, draws, losses, gf, ga, gd)` — 集計キャッシュ
2. 試合結果登録時に `standings_snapshot` をトランザクション内で同期更新。
3. `GET /tournaments/{id}/standings` は `standings_snapshot` から O(1) で返す。
4. ポイントルールは `point_win/draw/loss` で設定可能（ハードコードなし）。
5. ブラケット生成アルゴリズム: シード順に基づく対戦組み合わせを PHP で生成。

### ハマりポイント

- **ダブルエリミネーションのブラケット**: `is_lower_bracket` で上下ブラケットを管理したが、
  完全な実装には時間がかかりすぎた。今回はシングルイリミのみ完全実装。
- **`standings_snapshot` の同期**: 試合結果の更新・取り消し時のスナップショット再計算が必要。
- **シード順のランダム割り当て**: `ORDER BY RANDOM()` で実装したが、本番は決定論的シードが必要。

### 解決策 & 感想

高品質で完成（ダブルイリミは省略）。スナップショット方式の順位表は実用的。

> 「スナップショット方式はやはり効果的。
>  試合結果の更新・取り消し時の再計算が一番難しかった。
>  NENE2 でのスナップショット管理パターンの howto があれば参考になる。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成（部分省略あり）。スナップショット管理と複雑なブラケット設計が改善余地。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 三浦（新卒） | ○ 基本実装完成 | 3/5 | 順位計算 SQL の CASE WHEN |
| 中野（ロースキル） | ○ 実用的完成 | 3/5 | ブラケット自動更新、引き分け計算 |
| 小松（ベテラン） | ◎ 高品質完成（部分省略） | 4/5 | スナップショット再計算、ダブルイリミ |

**共通のフリクション**:
1. **スコア集計 CASE WHEN パターン** — 勝/負/引き分けをポイントに変換するSQL（複数シナリオで活用）。
2. **スナップショット集計の管理** — 結果更新時の再計算パターン（複数シナリオで言及）。
3. **`game-score-leaderboard-api.md` の発見性** — スポーツ・ゲーム系から自然に発見できる索引改善。
