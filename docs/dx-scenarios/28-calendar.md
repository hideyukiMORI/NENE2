# DX Scenario 28: カレンダー + 予定共有

## アプリ概要

予定・参加者・繰り返し・リマインダーを管理するカレンダー API。

| 機能 | エンドポイント例 |
|------|----------------|
| 予定作成 | `POST /events`（title, start_at, end_at, location, is_all_day） |
| 予定一覧 | `GET /events?from=2026-06-01&to=2026-06-30` |
| 参加者招待 | `POST /events/{id}/invitations`（user_id） |
| 出欠返答 | `PATCH /invitations/{id}`（status: accepted/declined/tentative） |
| 繰り返し設定 | `PATCH /events/{id}/recurrence`（rule: daily/weekly/monthly, until） |
| 繰り返し一覧 | `GET /events?recurring=true` |
| リマインダー | `POST /events/{id}/reminders`（remind_before_minutes） |
| 予定変更 | `PUT /events/{id}`（この回のみ変更 vs 以降すべて変更） |

ポイント: 繰り返しイベントの展開（ルールから個別発生を計算）、参加者の多対多、「この回のみ変更」。

---

## Persona A — 長澤 優斗（新卒・男性・22 歳）

### 背景

情報系専門学校卒業直後。Google カレンダーを毎日使うが「繰り返し」機能を作るとは思っていなかった。

### 作業シナリオ

1. `events` テーブルを作成。繰り返しは「繰り返し回数分の個別レコードを事前作成」する設計を選択。
   毎週の予定を 52 件 INSERT しようとして設計の問題に気づく。
2. 参加者管理を `events.attendees TEXT`（コンマ区切り）で実装。
3. 繰り返しルールを `events.recurrence TEXT`（`WEEKLY:Mon,Wed`）というカスタム文字列で定義。
4. 「この回のみ変更」の概念を理解できず、全ての繰り返しが同時に変わる設計になる。
5. `GET /events?from=...&to=...` で繰り返しイベントのフィルタが正しく機能しない。

### ハマりポイント

- **繰り返しの展開戦略**: 「事前全件生成」vs「ルールから動的展開」の選択。
- **「この回のみ変更」**: `event_exceptions(event_id, occurrence_date, override_start_at, ...)` テーブルが必要。
- **参加者のコンマ区切り**: 毎回のパターン。

### 解決策 & 感想

「繰り返しルールを保存して表示時に展開する」設計に変更。
`event_exceptions` テーブルの概念を先輩に教わった。

> 「繰り返しイベントって難しすぎ。
>  Google カレンダーの設計者すごいと思った。
>  まずはシンプルに繰り返しなしから実装すべきだったかも。」

### DX スコア: ⭐⭐（2/5）

繰り返し設計は複雑で新卒には難しい。段階的な実装ガイドが欲しい。

---

## Persona B — 三浦 奈緒子（ロースキル・女性・39 歳）

### 背景

グループウェア会社のサポートエンジニア 12 年。Outlook/Google Calendar の機能を熟知。

### 作業シナリオ

1. テーブル設計:
   - `events(id, user_id, title, start_at, end_at, is_all_day, recurrence_rule, recurrence_until)`
   - `event_exceptions(event_id, occurrence_date, modified_start_at, modified_end_at, is_cancelled)` — RRULE の例外
   - `event_invitations(event_id, user_id, status)` UNIQUE(event_id, user_id)
   - `event_reminders(event_id, user_id, remind_before_minutes)`
2. 繰り返し展開: `recurrence_rule = 'WEEKLY:MON,WED'` を PHP でパースして
   指定期間内の発生日を計算。`event_exceptions` で例外を適用。
3. `GET /events?from=...&to=...` は繰り返しイベントを PHP 側で展開してフィルタ。
4. 出欠返答は `PATCH /invitations/{id}` で `status` を更新。
5. 「この回のみ変更」は `event_exceptions` に `modified_start_at` を保存して対応。

### ハマりポイント

- **繰り返しルールのパース**: 自作パーサーが複雑になった。
  `iCalendar RRULE` 標準形式を使うべきか、カスタム形式にするか迷った。
- **繰り返し展開の PHP コスト**: 長期間の繰り返しを展開すると重い。キャッシュが必要か。
- **タイムゾーン**: グループウェアでは参加者ごとにタイムゾーンが違う可能性がある。今回は省略。

### 解決策 & 感想

動作するものは完成した。繰り返し展開のパフォーマンスは今後の課題。

> 「iCalendar の RRULE 形式を使えばよかった。
>  ライブラリ（sabre/vobject）があるし、標準に乗った方が良かった。
>  タイムゾーン問題はカレンダーアプリでは避けられないけど、
>  今回は省略した。」

### DX スコア: ⭐⭐⭐（3/5）

動作するものが完成。RRULE 標準形式と繰り返し展開パターンの howto が欲しい。

---

## Persona C — 杉本 哲朗（ベテラン・男性・50 歳）

### 背景

スケジューリングエンジン開発 20 年。iCalendar RFC 5545 に精通。

### 作業シナリオ

1. テーブル設計:
   - `events(id, user_id, title, start_at, end_at, timezone, rrule)` — `rrule` は RFC 5545 形式
   - `event_occurrences(event_id, occurrence_start, occurrence_end, is_exception, exception_start, exception_end)`
   - `event_invitations` / `event_reminders`
2. 繰り返し展開は `sabre/vobject` ライブラリの `EventIterator` を活用:
   ```php
   $iterator = new EventIterator($vevent);
   $iterator->fastForward(new DateTimeImmutable($from));
   while ($occurrence = $iterator->current()) { ... }
   ```
3. 「この回のみ変更」は `event_occurrences` の個別レコードに `exception_*` フィールドを設定。
4. `GET /events?from=...&to=...` は`event_occurrences` テーブルから高速取得
   （繰り返し展開済みのレコードをキャッシュ）。
5. タイムゾーン: 全 `start_at` / `end_at` を UTC で保存、`timezone` カラムで元のタイムゾーンを記録。

### ハマりポイント

- **`sabre/vobject` の NENE2 統合**: `composer require sabre/vobject` で問題なく追加できたが、
  NENE2 のサービスプロバイダーへの登録方法を確認。
- **event_occurrences の事前生成**: いつ展開するかのタイミング（CREATE 時 vs GET 時）と
  保持期間（1 年先まで？）の設計判断。
- **タイムゾーン変換**: UTC から元のタイムゾーンへの変換を PHP `DateTimeZone` で実装。

### 解決策 & 感想

高品質で完成。`sabre/vobject` の統合はスムーズだった。

> 「iCalendar の繰り返しはライブラリに任せるのが正解。
>  NENE2 での外部ライブラリ統合パターンの howto があれば嬉しい。
>  カレンダーのタイムゾーン問題は本当に難しいので、
>  UTC での統一保存を公式推奨にしてほしい。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。外部ライブラリ統合パターンと UTC 統一ポリシーのドキュメントが欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 長澤（新卒） | △ 繰り返し設計で詰まり | 2/5 | 繰り返しイベントの設計複雑さ |
| 三浦（ロースキル） | ○ 動作するものが完成 | 3/5 | RRULE 標準形式、繰り返し展開パフォーマンス |
| 杉本（ベテラン） | ◎ 高品質完成 | 4/5 | 外部ライブラリ統合パターン、UTC ポリシー |

**共通のフリクション**:
1. **タイムゾーン UTC 統一ポリシー** — 複数シナリオで繰り返し言及。公式ガイドラインが最重要。
2. **繰り返しイベントの段階的実装ガイド** — まずは繰り返しなし → 次に固定繰り返し → 例外対応。
3. **外部ライブラリ統合パターン** — `composer require` してサービスプロバイダーに登録する howto。
