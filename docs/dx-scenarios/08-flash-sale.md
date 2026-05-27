# DX Scenario 08: フラッシュセール

## アプリ概要

タイムセール・在庫ロック・購入制限・購買ログを備えた限定セール API。

| 機能 | エンドポイント例 |
|------|----------------|
| セール管理 | `GET /sales`, `POST /sales`（start_at, end_at, max_quantity）|
| セール商品 | `POST /sales/{id}/items`（product_id, sale_price, stock_limit） |
| セール状態確認 | `GET /sales/{id}/status`（開始前/進行中/終了・残数） |
| 購入 | `POST /sales/{id}/purchase`（product_id, user_id） |
| 購買ログ | `GET /sales/{id}/logs`（誰が何をいつ買ったか） |
| ユーザー制限確認 | `GET /sales/{id}/purchase-limit/{user_id}`（1 ユーザー最大 N 件） |

ポイント: 時刻チェック（セール有効期間）、在庫の競合更新防止、購入上限（ユーザー per セール）。

---

## Persona A — 中島 りな（新卒・女性・23 歳）

### 背景

デザイン×エンジニアリング系の専門学校卒。HTML/CSS/JavaScript が得意で PHP は 1 年目。
EC サイト周りのアルバイト経験あり。

### 作業シナリオ

1. `sales` / `sale_items` / `purchase_logs` テーブルを作る。
2. セール有効期間チェックを PHP の `new DateTime()` で比較して実装。
3. 在庫デクリメントを「SELECT 残数 → 残数 > 0 なら UPDATE」で実装（トランザクションなし）。
4. ユーザー購入制限を「COUNT して上限チェック」で実装するがトランザクションがないため
   競合する可能性がある。
5. `GET /sales/{id}/status` で「現在時刻がセール期間内かどうか」を毎回 PHP で計算。

### ハマりポイント

- **同時購入競合**: 同時に 2 人が最後の 1 個を買うシナリオでダブルセールが発生。
- **タイムゾーン**: サーバーの `date_default_timezone_get()` がどこかで UTC でない設定になっており、
  セール開始時刻が 9 時間ずれるバグが発生。
- **購入上限の競合**: トランザクションなしで「COUNT → 上限チェック → INSERT」の間に他が入れる。

### 解決策 & 感想

先輩から「フラッシュセールは競合が一番の問題」と教わり、トランザクションを全体にかけた。
タイムゾーンは PHP の `date_default_timezone_set('UTC')` を追加して解決。

> 「同時に買う競合って、テストしてても気づかないんだよね。
>  本番でエラーが出て初めて分かった。
>  タイムゾーンは本当に罠。UTCに統一しないとダメと学んだ。」

### DX スコア: ⭐⭐（2/5）

競合・タイムゾーン両方でバグ発生。同時実行制御の howto が必要。

---

## Persona B — 野村 誠（ロースキル・男性・37 歳）

### 背景

フリーランス PHP エンジニア 9 年目。WordPress + WooCommerce での EC 構築経験多数。
「競合はサーバー側で防ぐもの」という認識はある。

### 作業シナリオ

1. テーブル設計: `sales` / `sale_items` / `purchase_logs`。`sale_items.remaining_stock` カラム。
2. 購入処理をトランザクション内で実装:
   ```
   BEGIN → SELECT remaining_stock → バリデーション → UPDATE remaining_stock-=1 → INSERT log → COMMIT
   ```
3. タイムゾーンは `created_at = datetime('now')` を SQLite で使用（UTC で統一できた）。
4. ユーザー購入制限はトランザクション内で `COUNT(*) FROM purchase_logs WHERE user_id=? AND sale_id=?` でチェック。
5. セール開始前のアクセスに 403 を返す（本来は 422 または 404 の方が適切）。

### ハマりポイント

- **SQLite の `datetime('now')`**: UTC で返ることを確認したが、ドキュメントに明示がなく不安。
- **HTTP ステータスコード**: セール開始前は何を返すべきか（403 vs 422 vs 404）が分からない。
- **大量アクセス**: 「1000 人が同時に購入したら？」というシナリオで SQLite の
  ロック待ちタイムアウトが心配。

### 解決策 & 感想

トランザクションで競合はほぼ防げた。HTTP ステータスは先輩に聞いて 422 に修正。

> 「SQLite のタイムゾーンって UTC 固定なの？確認するのに時間かかった。
>  大量アクセスは SQLite だと限界があるのは分かってるけど、
>  その切り替え時のガイドが欲しい。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。SQLite の制約と HTTP ステータスの説明が改善余地。

---

## Persona C — 金子 美幸（シニア・女性・42 歳）

### 背景

ゲーム会社のバックエンドエンジニア 14 年。高並列リアルタイム処理の実務経験。
「フラッシュセールは最もタイミング攻撃を受けやすい機能の一つ」という認識。

### 作業シナリオ

1. 在庫を `sale_items.remaining_stock` で管理し、`CHECK(remaining_stock >= 0)` 制約を追加。
2. 購入処理で `UPDATE sale_items SET remaining_stock = remaining_stock - 1 WHERE id=? AND remaining_stock > 0`
   のアトミック UPDATE を使い、影響行数 0 なら「在庫なし 422」を返す。
3. ユーザー購入制限は `INSERT OR IGNORE INTO purchase_limits(user_id, sale_id, count)` +
   `UPDATE SET count = count + 1 WHERE count < max_limit` パターンを検討。
   (SQLite の UPSERT)
4. 時刻チェックは `WHERE start_at <= datetime('now') AND end_at > datetime('now')` で DB 側に委ねる。
5. OpenAPI でフラッシュセール固有のエラー（在庫切れ/制限超過/期間外）を定義。

### ハマりポイント

- **アトミック UPDATE のパターン**: NENE2 の `DatabaseQueryExecutorInterface::execute()` が
  影響行数を返すかどうかを `src/` で確認した（返す: `rowCount()` を内包していた）。
- **SQLite UPSERT**: `ON CONFLICT DO UPDATE` の構文が howto にない。
- **競合ウィンドウ**: SQLite は書き込みロックで全部シリアライズされるため、
  今回は競合が起きない。MySQL ではまた違う話になる。

### 解決策 & 感想

アトミック UPDATE パターンで高品質に完成。`rowCount()` の確認に少し時間がかかった。

> 「アトミック UPDATE は EC でよく使うパターン。
>  howto に 'UPDATE ... WHERE condition AND check' のパターンがあれば嬉しい。
>  SQLite UPSERT も実例があると良い。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。アトミック UPDATE と UPSERT の howto が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 中島（新卒） | △ バグあり | 2/5 | 同時実行競合、タイムゾーン罠 |
| 野村（ロースキル） | ○ 実用的完成 | 3/5 | SQLite タイムゾーン確認、HTTP ステータス |
| 金子（シニア） | ◎ 高品質完成 | 4/5 | アトミック UPDATE パターン、SQLite UPSERT |

**共通のフリクション**:
1. **同時実行競合の howto がない** — トランザクション + アトミック UPDATE パターンの実例。
2. **SQLite のタイムゾーン動作** — `datetime('now')` が UTC を返すことの明示的な説明。
3. **HTTP ステータスコードのガイドライン** — 業務ルール違反（期間外/在庫切れ）の適切なコード選択。
