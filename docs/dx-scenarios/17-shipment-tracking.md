# DX Scenario 17: 配送追跡

## アプリ概要

荷物・ステータス更新・通知を管理する配送追跡 API。

| 機能 | エンドポイント例 |
|------|----------------|
| 荷物登録 | `POST /shipments`（tracking_number, sender_id, recipient） |
| 状態更新 | `POST /shipments/{id}/events`（status, location, note） |
| 追跡 | `GET /shipments/{tracking_number}`（全イベント履歴付き） |
| 一覧 | `GET /shipments?status=in_transit&page=1` |
| 通知設定 | `POST /shipments/{id}/notifications`（email/webhook） |
| 到着予定更新 | `PATCH /shipments/{id}/estimated-delivery` |
| 未着アラート | `GET /shipments/overdue`（予定日超過） |

ポイント: ステータス遷移（集荷→輸送中→配達済み/不在）、イベントログ（append-only）、予定日管理。

---

## Persona A — 宮田 涼（新卒・男性・24 歳）

### 背景

理系大学卒業直後。宅配システムを使ったことはあるが内部構造は意識したことない。

### 作業シナリオ

1. `shipments` テーブルに `status TEXT` を直接持たせる。
2. 「状態更新」を `PATCH /shipments/{id}` の `status` フィールドで実装
   （イベント履歴が残らない設計）。
3. 追跡番号を `AUTOINCREMENT` の ID をそのまま使う（`tracking_number` は同じ）。
   `GET /shipments/12345` で ID 番号が丸見えになる。
4. 通知設定は「メールアドレスを保存するだけ」で通知の送信実装なし。
5. 不在配達時の再配達スケジュールを実装しようとして複雑さに気づき諦める。

### ハマりポイント

- **イベントログの重要性**: 「現在のステータス」だけ保存すると「いつどこで何があったか」が分からない。
- **追跡番号の設計**: 連番 ID ではなく、ランダムまたは意味のある追跡番号が必要。
- **ステータス遷移ルール**: 「配達済み」→「輸送中」への逆行が可能な設計になる。

### 解決策 & 感想

`event-sourcing-cqrs-api.md` のイベントログパターンを適用。
追跡番号は `STR_PAD(random_int(0, 999999999), 12, '0', STR_PAD_LEFT)` で生成した。

> 「イベント履歴って大事なんだ、って配送会社のアプリ使ってて気づいた。
>  現在ステータスだけ保存してたら佐川急便みたいな追跡ページ作れなかった。
>  event-sourcing howto は難しかったけど考え方は分かった。」

### DX スコア: ⭐⭐⭐（3/5）

event-sourcing howto の活用で改善可能。追跡番号生成の howto が欲しい。

---

## Persona B — 福田 かおり（ロースキル・女性・35 歳）

### 背景

物流会社の IT 部門 8 年目。ヤマトや佐川のシステムを外側から見てきた業務経験あり。

### 作業シナリオ

1. テーブル設計:
   - `shipments(id, tracking_number, sender_id, recipient_name, recipient_address, current_status, estimated_delivery, created_at)`
   - `shipment_events(id, shipment_id, status, location, note, event_at)`
2. 追跡番号は `YYYYMMDD` + `random_int(10000,99999)` の組み合わせで生成。
3. 状態更新は `shipment_events` に INSERT + `shipments.current_status` を UPDATE（両方更新）。
4. 遷移ルールを `state-machine-workflow-api.md` のパターンで実装。
5. 未着アラート: `WHERE estimated_delivery < date('now') AND current_status NOT IN ('delivered','returned')`

### ハマりポイント

- **2 テーブルの同期**: `shipment_events` INSERT と `shipments.current_status` UPDATE を
  トランザクション内でまとめる必要があったが、最初忘れていた。
- **ステータスの重複管理**: `current_status` を `shipments` と `shipment_events` 両方に持つのが
  冗長だが、パフォーマンスのために妥協。
- **通知の実装**: メール送信が必要だが、NENE2 に標準機能がないため省略。

### 解決策 & 感想

業務知識でスムーズに設計できた。`state-machine-workflow-api.md` が直接活用できた。

> 「state-machine howto はそのまま使えた。
>  2 テーブル同期のトランザクション忘れはレビューで指摘してもらえた。
>  こういう『忘れやすいところ』をチェックリストにしておくといいと思う。」

### DX スコア: ⭐⭐⭐（3/5）

良好に完成。トランザクションの「忘れ防止チェックリスト」的な補足が欲しい。

---

## Persona C — 内田 浩（ベテラン・男性・48 歳）

### 背景

国際物流システム開発 20 年。IATA 標準の貨物追跡システム設計経験あり。

### 作業シナリオ

1. テーブル設計（物流ドメインの観点）:
   - `shipments(id, tracking_number, consignee_name, consignee_address, service_type, current_status, estimated_delivery)`
   - `tracking_events(id, shipment_id, event_code, location_code, description, occurred_at)`
   - `event_code` は標準化された値（`PICKED_UP/IN_TRANSIT/ARRIVED/OUT_FOR_DELIVERY/DELIVERED/FAILED`）
2. 追跡番号: `bin2hex(random_bytes(8))` + チェックデジット（簡易版）。
3. 状態遷移: `TrackingStateMachine::transit()` UseCase で遷移ルールを管理。
   逆行は 422 で拒否。
4. `GET /shipments/overdue` は INDEX を活用: `estimated_delivery` カラムにインデックス。
5. 通知は `ShipmentNotificationPort` インターフェースを定義、実装は省略
   （Email/SMS/Webhook のモック実装のみ）。

### ハマりポイント

- **チェックデジット**: 単純な `bin2hex(random_bytes())` では追跡番号の誤打チェックができない。
  実装すると複雑になるため今回は省略。
- **タイムゾーン**: 国際配送では UTC で全ログを保存し、表示時にタイムゾーン変換が必要。
  今回は UTC 固定。
- **SQLite のインデックス確認**: `EXPLAIN QUERY PLAN` で `estimated_delivery` インデックスが
  使われることを確認した。

### 解決策 & 感想

高品質で完成。国際化対応（タイムゾーン）は要件外として省略。

> 「追跡番号にチェックデジットを入れるかどうかは要件次第。
>  今回は省略したが、howto にランダム追跡番号のベストプラクティスを
>  書いておくと良いと思う。
>  タイムゾーン問題は複数のシナリオで出てくるから、
>  NENE2 公式の UTC 推奨ポリシーがあれば助かる。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。追跡番号生成パターンとタイムゾーンポリシーの説明が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 宮田（新卒） | ○ howto 活用で改善 | 3/5 | イベントログの設計、追跡番号生成 |
| 福田（ロースキル） | ○ 業務知識で完成 | 3/5 | 2 テーブル同期のトランザクション忘れ |
| 内田（ベテラン） | ◎ 高品質完成 | 4/5 | 追跡番号ベストプラクティス、タイムゾーン |

**共通のフリクション**:
1. **追跡番号生成パターン** — `random_bytes()` / UUID / 構造化番号のベストプラクティス howto。
2. **タイムゾーン UTC ポリシー** — 複数シナリオで繰り返される問題。公式ガイドラインが欲しい。
3. **イベントログ + 現在ステータスの 2 テーブル同期** — append-only ログと snapshot の組み合わせパターン。
