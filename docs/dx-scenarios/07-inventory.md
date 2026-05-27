# DX Scenario 07: 在庫管理

## アプリ概要

商品・倉庫・入出庫・在庫アラートを管理する API。

| 機能 | エンドポイント例 |
|------|----------------|
| 商品管理 | `GET /products`, `POST /products`, `PUT /products/{id}` |
| 倉庫管理 | `GET /warehouses`, `POST /warehouses` |
| 在庫確認 | `GET /inventory?product_id=1&warehouse_id=2` |
| 入庫 | `POST /stock-movements`（type: `in`, product_id, warehouse_id, quantity） |
| 出庫 | `POST /stock-movements`（type: `out`, quantity）← 在庫不足なら 422 |
| 移庫 | `POST /stock-transfers`（from_warehouse_id, to_warehouse_id, quantity） |
| アラート設定 | `POST /alert-thresholds`, `GET /alerts`（閾値以下の商品一覧） |

ポイント: 在庫数の一貫性（在庫不足防止）、入出庫ログ（イベントソーシング的）、アラート集計。

---

## Persona A — 石橋 夏希（新卒・女性・22 歳）

### 背景

商業高校→専門学校卒業直後。簿記 2 級持ち。「在庫」の概念は分かるが API 設計は初めて。

### 作業シナリオ

1. `products` テーブルに `stock_quantity` カラムを直接持たせる設計を選ぶ
   （`stock_movements` テーブルは思いつかない）。
2. 入庫は `stock_quantity += quantity` の UPDATE。出庫は `-= quantity`。
3. 在庫不足チェックを「SELECT してから UPDATE」で実装。トランザクションなし。
4. 移庫を「A 倉庫から出庫 + B 倉庫に入庫」の 2 回 API 呼び出しで設計
   （クライアントが 2 回叩く設計にしてしまう）。
5. アラートは「全商品を取得して PHP ループで閾値チェック」する実装。

### ハマりポイント

- **在庫ログの概念**: `stock_quantity` を直接更新する設計で履歴が残らない。
- **同時更新の競合**: 複数ユーザーが同時に在庫を更新したとき数値が狂う。
- **移庫のアトミック性**: 2 回 API 呼び出しにすると、中間状態（出庫済み・未入庫）が生まれる。

### 解決策 & 感想

`docs/howto/event-sourcing-cqrs-api.md` を先輩に紹介してもらい、
入出庫ログを append-only で持つ設計に変更した。

> 「簿記の感覚だと『仕訳帳』があって元帳があるのに、
>  最初それを API でどう表現するか分からなかった。
>  event-sourcing の howto でやっと分かった気がした。」

### DX スコア: ⭐⭐⭐（3/5）

簿記知識が活きた。append-only ログの howto への誘導があれば初期設計が改善される。

---

## Persona B — 浜田 剛（ロースキル・男性・40 歳）

### 背景

中小製造業の IT 担当 12 年。ERP（SAP）の運用経験あり。PHP は独学。

### 作業シナリオ

1. `products` / `warehouses` / `stock_movements` テーブル設計
   （ERP の経験から在庫移動ログが必要と直感）。
2. 在庫残高は `stock_movements` を SUM する「計算ビュー」方式で実装:
   ```sql
   SELECT SUM(CASE WHEN type='in' THEN quantity ELSE -quantity END) AS balance
   FROM stock_movements WHERE product_id=? AND warehouse_id=?
   ```
3. 出庫バリデーションはトランザクション内で現在残高を計算してから INSERT。
4. 移庫は単一トランザクション内で 2 件の `stock_movements` を INSERT。良好。
5. アラートは `HAVING balance < threshold` で一発 SQL クエリ。

### ハマりポイント

- **残高計算の SQL**: `HAVING` 句を正しく使えたが、全件 JOIN での計算が大量データで遅い。
  「集計用テーブルを作る」= マテリアライズドビューの概念は知っているが NENE2 での実装方法が不明。
- **トランザクションの書き方**: `tx->run()` の使い方を `add-database-endpoint.md` で確認
  （説明が薄く少し時間がかかった）。
- **アラート通知の配信**: 「在庫不足を検知したらメールを送る」実装を NENE2 でどうするか不明。

### 解決策 & 感想

ERP 経験が活きて設計は良好。大量データへの対応は宿題として残した。

> 「トランザクションの書き方、サンプルが 1 個しかなくてちょっと困った。
>  あと大量データのときに集計 SQL が遅くなるのは分かってるんだけど、
>  NENE2 でマテリアライズドビュー的なことをどうやるかが分からなかった。」

### DX スコア: ⭐⭐⭐（3/5）

良好な設計。大量データ対応と通知統合のガイドが欲しい。

---

## Persona C — 三浦 達也（シニア・男性・45 歳）

### 背景

物流系スタートアップ CTO 経験。PostgreSQL + Kafka で高スループット在庫システムを
構築した実績あり。「NENE2 でどこまでできるか試したい」という姿勢。

### 作業シナリオ

1. イベントソーシング的な `stock_movements` テーブルで設計。
2. 在庫残高は `stock_snapshots` テーブルに定期的に計算結果をキャッシュ（手動実装）。
   新しい `stock_movements` があれば `snapshot + delta` で計算。
3. 出庫処理はトランザクション内でスナップショット + デルタ計算 → バリデーション → INSERT の順。
4. 移庫は `StockTransferUseCase` として独立した UseCase に。
5. `GET /alerts` は SQL で `WHERE balance < threshold` をサブクエリ込みで一発実装。

### ハマりポイント

- **スナップショット戦略**: NENE2 に「定期実行」機能がないため、スナップショット更新を
  「移動 INSERT のたびに同期的に更新する」方式で妥協。
- **同時実行制御**: SQLite では `SELECT ... FOR UPDATE` がなく、
  高スループット要件では NENE2 + SQLite の限界を感じた。
- **`event-sourcing-cqrs-api.md` との整合**: howto の `domain_events` パターンと
  今回の `stock_movements` パターンを統合できるか不明だった。

### 解決策 & 感想

機能的には完成。本番スケールに向けては MySQL + Kafka への移行を念頭に置いた。

> 「NENE2 の設計は綺麗で気に入った。ただ高スループット系は SQLite と相性が悪い。
>  スナップショット実装パターンの howto があれば参考にしたかった。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。スナップショット・スケーリングのドキュメントが改善余地。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 石橋（新卒） | ○ 設計改善後完成 | 3/5 | append-only ログ設計への誘導 |
| 浜田（ロースキル） | ○ 良好な設計 | 3/5 | トランザクション例の薄さ、スナップショット方法 |
| 三浦（シニア） | ◎ 高品質完成 | 4/5 | スナップショット戦略、同時実行制御の限界 |

**共通のフリクション**:
1. **トランザクション使用例が少ない** — `tx->run()` の実例を howto で増やす必要がある。
2. **スナップショット（集計キャッシュ）パターン** — 大量データに対応する集計キャッシュの howto。
3. **append-only ログ設計の入り口** — `event-sourcing-cqrs-api.md` は高度すぎて、
   シンプルな「ログテーブル + 残高計算」パターンの howto が別に欲しい。
