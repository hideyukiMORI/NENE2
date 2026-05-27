# DX Scenario 14: イベント申込

## アプリ概要

イベント・定員・申込・キャンセル待ちを管理する申込管理 API。

| 機能 | エンドポイント例 |
|------|----------------|
| イベント管理 | `GET /events`, `POST /events`（title, starts_at, capacity） |
| 申込 | `POST /events/{id}/registrations`（user_id） |
| キャンセル | `DELETE /registrations/{id}` |
| キャンセル待ち自動繰り上げ | （キャンセル発生時に先頭のキャンセル待ちを繰り上げ） |
| 申込一覧 | `GET /events/{id}/registrations?status=registered` |
| マイ申込 | `GET /users/{id}/registrations` |
| 定員確認 | `GET /events/{id}/capacity`（残席数・キャンセル待ち数） |

ポイント: 定員オーバー時のキャンセル待ちキュー（FIFO）、キャンセル繰り上げ処理のアトミック性。

---

## Persona A — 渡辺 涼夏（新卒・女性・23 歳）

### 背景

商業系大学卒後プログラミングスクール経由で就職 1 年目。イベント参加者管理の業務経験あり。

### 作業シナリオ

1. `events` と `registrations` テーブルを作成。`events.capacity INTEGER` で定員管理。
2. 申込時の定員チェック: `COUNT(*) FROM registrations WHERE event_id=?` と比較。
   トランザクション外で実装。
3. キャンセル待ちの概念を理解しておらず、定員オーバーは「エラーを返すだけ」にしてしまう。
4. キャンセル処理は `DELETE` で物理削除。キャンセル待ちの繰り上げ処理なし。
5. 申込番号（連番）を `MAX(registration_number)+1` で実装。競合に弱い。

### ハマりポイント

- **キャンセル待ちキューの概念**: 「定員オーバー = エラー」ではなく「キャンセル待ちに入れる」
  という設計を思いつかない。
- **繰り上げ処理のアトミック性**: キャンセルとキャンセル待ち繰り上げを同一トランザクションで
  行う必要性を理解していない。
- **申込番号の採番**: 競合に弱い `MAX+1` 方式の問題。

### 解決策 & 感想

「キャンセル待ちキュー」を先輩に教えてもらい再設計した。
`state-machine-workflow-api.md` のステータス遷移を参考にした。

> 「キャンセル待ちって仕様書に書いてあったけど、
>  実装のイメージが全然湧かなかった。
>  イベント系って独特の要件があるんだなと思った。」

### DX スコア: ⭐⭐（2/5）

キャンセル待ちキューを実装できず再設計が必要。業務パターン howto が欲しい。

---

## Persona B — 山田 健介（ロースキル・男性・33 歳）

### 背景

イベント会社の IT 担当 6 年目。Peatix の管理ツールを業務で使っており、
申込管理の業務ロジックは分かっている。

### 作業シナリオ

1. `registrations.status` = `registered / waitlisted / cancelled` で状態管理。
2. 申込処理:
   - 定員未満 → `status = 'registered'`
   - 定員以上 → `status = 'waitlisted'` + `waitlist_position` で順番管理
3. キャンセル時の繰り上げ: `status = 'cancelled'` に変更後、`waitlisted` 先頭の 1 件を
   `registered` に変更するロジックをトランザクション内で実装。
4. `waitlist_position` は `MAX(waitlist_position)+1` で採番（競合あり）。
5. `GET /events/{id}/capacity` でリアルタイムの残席数を `COUNT(*)` で計算。

### ハマりポイント

- **`waitlist_position` の採番競合**: 同時に複数人がキャンセル待ちに入った場合、
  同じ順番番号が付く可能性がある。
- **繰り上げ後の通知**: 繰り上げになったユーザーへの通知をどう実装するか不明（省略）。
- **キャンセル待ちの公平性**: FIFO で繰り上げることを保証するクエリ
  (`ORDER BY created_at LIMIT 1`) が正しいか確認が必要だった。

### 解決策 & 感想

業務知識でキャンセル待ちロジックを設計できた。採番競合は「実害が出てから直す」で妥協。

> 「繰り上げロジックはトランザクション内でまとめてやるのは分かった。
>  でも waitlist_position の採番、同時アクセスで番号が被るのが怖い。
>  シリアル採番の howto ないかな。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。採番競合と通知連携の改善余地あり。

---

## Persona C — 大塚 妙子（シニア・女性・40 歳）

### 背景

チケット販売システム開発 12 年。コンサートや展示会の申込システムを設計してきた。

### 作業シナリオ

1. テーブル設計:
   - `registrations(id, event_id, user_id, status, waitlist_position, registered_at, cancelled_at)`
   - `status`: `registered | waitlisted | cancelled`
   - `UNIQUE(event_id, user_id)` — 同一イベント二重申込防止
2. 申込はトランザクション内で:
   ```sql
   -- 現在の申込数を取得（ロック目的で更新も含む形で）
   SELECT COUNT(*) FROM registrations WHERE event_id=? AND status='registered'
   -- 定員未満なら INSERT status='registered'、それ以外なら status='waitlisted'
   ```
3. `waitlist_position` は `SELECT MAX(waitlist_position)+1 FROM registrations WHERE event_id=? AND status='waitlisted'`
   をトランザクション内で計算（ロックされているため安全）。
4. キャンセル繰り上げ: トランザクション内で `status='waitlisted' ORDER BY waitlist_position LIMIT 1`
   を `registered` に更新。
5. 申込確定・繰り上げ時の「通知フック」を `NotificationEvent::emit()` として記録
   （実際の通知は非同期化推奨と注記）。

### ハマりポイント

- **SQLite のロック粒度**: テーブルレベルロックで、トランザクション内の `SELECT COUNT` が
  実際に他のトランザクションをブロックするか確認が必要だった。
- **繰り上げの連鎖**: 「1 件キャンセルで 1 件繰り上げ」が原則だが、
  繰り上げ候補が「申込済み上限を超えたら辞退」というポリシーを追加したくなった。
- **通知の非同期化**: NENE2 でのキュー・ジョブの標準パターンが存在しないため、
  通知は「記録するだけ」にして実際の送信は省略。

### 解決策 & 感想

高品質で完成。SQLite ロックの挙動は実験で確認した。

> 「SQLite はトランザクション内で書き込みロックが取れるから、
>  SELECT COUNT → INSERT のパターンで競合は防げる。
>  でもこれは SQLite 固有の挙動なので、howto に注記があれば嬉しい。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。SQLite ロック挙動の説明と非同期通知パターンが欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 渡辺（新卒） | △ 再設計必要 | 2/5 | キャンセル待ちキューの業務パターン |
| 山田（ロースキル） | ○ 実用的完成 | 3/5 | 採番競合対策、通知連携 |
| 大塚（シニア） | ◎ 高品質完成 | 4/5 | SQLite ロック動作説明、非同期通知 |

**共通のフリクション**:
1. **FIFO キューパターンの howto がない** — キャンセル待ちキューはイベント系だけでなく
  多くのアプリで使われる。`waitlisted/position` パターンの実例が欲しい。
2. **採番の競合対策** — `MAX+1` 方式の問題点とシリアル採番の代替パターン。
3. **SQLite トランザクションロック動作の説明** — 他 DB との違いをドキュメント化。
