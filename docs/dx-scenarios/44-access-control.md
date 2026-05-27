# DX Scenario 44: 入退室管理

## アプリ概要

従業員・ゲート・入退室ログ・月次集計を管理する入退室管理 API。

| 機能 | エンドポイント例 |
|------|----------------|
| ゲート管理 | `GET /gates`, `POST /gates`（name, location, type: entry/exit/both）|
| カード管理 | `POST /cards`（employee_id, card_code） |
| 入退室記録 | `POST /gates/{id}/events`（card_code, event_type: entry/exit, occurred_at）|
| 現在在室 | `GET /gates/{id}/present`（今室内にいる人）|
| 入退室履歴 | `GET /employees/{id}/access-log?date=2026-06-01` |
| 月次集計 | `GET /access-log/monthly?year=2026&month=6`（出勤日数・平均在室時間）|
| 異常検知 | `GET /access-log/anomalies`（退室なしに再入室等）|

ポイント: 入室・退室のペアリング（時間計算）、現在在室の判定、不正アクセスパターン検知。

---

## Persona A — 山口 直也（新卒・男性・22 geq 歳）

### 背景

セキュリティ系専門学校卒 1 年目。「入退室管理ってどのビル にも ある システム」程度の理解。

### 作業シナリオ

1. `employees` / `access_logs(employee_id, event_type, occurred_at)` テーブルを作成。
2. 「現在在室」を「最後の event_type が 'entry' の人」と定義して実装。
3. 在室時間計算を「入室時刻と退室時刻のペアを PHP でマッチング」する実装（複雑）。
4. 月次集計を PHP ループで全ログから計算（遅い可能性）。
5. カードの概念がなく、`employee_id` を直接 POST する設計。

### ハマりポイント

- **最後の event の検出**: `SELECT * ORDER BY occurred_at DESC LIMIT 1` が全員分必要。
- **入退室のペアリング**: entry と exit を時系列でペアにする SQL が複雑。
- **ゲートの概念**: カードがゲートをスキャンする設計が思いつかない。

### 解決策 & 感想

「最後の entry/exit」を `LAG/LEAD` ウィンドウ関数でペアリングする方法を先輩に教わった。

> 「入退室ってシンプルそうで DB 設計が複雑。
>  ペアリングって考えたことなかった。
>  LAG 関数、また出てきた。SQLite の便利機能として覚えた。」

### DX スコア: ⭐⭐（2/5）

入退室のペアリング設計で詰まった。ウィンドウ関数 howto が解決の鍵。

---

## Persona B — 河野 聡子（ロースキル・女性・35 geq 歳）

### 背景

ビル管理会社の IT 担当 10 年。入退室管理システムの運用経験あり。

### 作業シナリオ

1. テーブル設計:
   - `employees(id, name, card_code)` UNIQUE(card_code)
   - `gates(id, name, location, type)` — entry/exit/both
   - `access_events(id, gate_id, card_code, event_type, occurred_at)` + インデックス(`card_code, occurred_at`)
2. 現在在室:
   ```sql
   SELECT e.* FROM employees e
   WHERE (SELECT event_type FROM access_events
     WHERE card_code = e.card_code ORDER BY occurred_at DESC LIMIT 1) = 'entry'
   ```
3. 在室時間のペアリング:
   ```sql
   SELECT ae.occurred_at AS entry_time,
     (SELECT MIN(ae2.occurred_at) FROM access_events ae2
       WHERE ae2.card_code = ae.card_code AND ae2.event_type='exit' AND ae2.occurred_at > ae.occurred_at) AS exit_time
   FROM access_events ae WHERE ae.event_type='entry' AND ae.card_code=?
   ```
4. 月次集計: 在室時間の合計 `SUM(julianday(exit_time) - julianday(entry_time)) * 24` で時間数。
5. 異常検知: 「退室なしに再入室」= 同一カードの連続 entry イベントを検出。

### ハマりポイント

- **相関サブクエリのパフォーマンス**: 在室時間ペアリングの相関サブクエリが大量データで遅い。
  `LAG/LEAD` ウィンドウ関数を後で試した。
- **日付またがりの在室**: 深夜 0 時をまたいで在室している場合の月次集計の扱い。
- **退室なし（強制帰宅）**: 在室のまま翌日になった場合の「退室なし」ログの処理ポリシー。

### 解決策 & 感想

実用的に完成できた。ウィンドウ関数の方がシンプルだと後で気づいた。

> 「相関サブクエリよりウィンドウ関数の方がスッキリ書けた。
>  でも最初は相関サブクエリで動いたから後回しにした。
>  パフォーマンス気にするならウィンドウ関数 howto 最初から読みたかった。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。ウィンドウ関数と日付またがりの在室処理 howto が欲しい。

---

## Persona C — 菊地 剛（ベテラン・男性・50 geq 歳）

### 背景

セキュリティシステム会社のアーキテクト 20 年。生体認証・カード認証の設計経験あり。

### 作業シナリオ

1. テーブル設計（セキュリティ重視）:
   - `cards(id, card_code, employee_id, status: active/suspended, issued_at, expires_at)` — カードの管理
   - `access_events` に `is_authorized BOOLEAN` と `denial_reason` を追加
   - `access_sessions(employee_id, entry_event_id, exit_event_id, session_minutes)` — ペアリング済みセッション
2. 入室記録時にカードの有効性をチェック（期限切れ・停止カードは拒否 → 記録はするが `is_authorized=false`）。
3. `access_sessions` はリアルタイム更新: entry → セッション作成（exit_event_id=NULL）、exit → セッション更新。
4. 月次集計: `SUM(session_minutes) GROUP BY employee_id` で高速。
5. 異常検知: `WHERE exit_event_id IS NULL AND CAST(julianday('now') - julianday(entry_event.occurred_at) AS INTEGER) > 1`（1 日以上退室なし）。

### ハマりポイント

- **`access_sessions` の維持**: entry と exit の 2 つのイベントを正しくペアリングしてセッションを更新するロジック。
- **カード停止の即時反映**: `cards.status = 'suspended'` にしてもセッション内でキャッシュが残らないように注意。
- **不正アクセス記録**: `is_authorized=false` の記録をセキュリティアラートとして通知する設計（今回は省略）。

### 解決策 & 感想

高品質で完成。`access_sessions` の維持ロジックが一番複雑だった。

> 「セッションテーブルでペアリングを管理するパターンは
>  入退室だけでなく、ログイン/ログアウト・作業開始/終了にも使えるパターン。
>  howto に 'セッション型イベントペアリング' として書いてほしい。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。セッション型イベントペアリングパターンと SQLite ウィンドウ関数が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 山口（新卒） | △ ペアリング設計で詰まり | 2/5 | 入退室ペアリング、ウィンドウ関数 |
| 河野（ロースキル） | ○ 実用的完成 | 3/5 | 相関サブクエリ vs ウィンドウ関数 |
| 菊地（ベテラン） | ◎ 高品質完成 | 4/5 | セッション型ペアリングパターン |

**共通のフリクション**:
1. **SQLite ウィンドウ関数 (`LAG/LEAD/ROW_NUMBER`)** — 複数シナリオで必要。最重要 howto 候補。
2. **「最新レコードのみを取得」パターン** — `ORDER BY ... LIMIT 1` サブクエリの繰り返し。
3. **イベントペアリング設計** — entry/exit、開始/終了のペアをセッションテーブルで管理するパターン。
