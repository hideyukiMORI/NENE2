# DX Scenario 32: ギフトカード管理

## アプリ概要

ギフトカードの発行・残高管理・利用・失効を管理する API。

| 機能 | エンドポイント例 |
|------|----------------|
| 発行 | `POST /gift-cards`（code, initial_balance_cents, expires_at） |
| 一括発行 | `POST /gift-cards/batch`（count, amount_cents, expires_at） |
| 残高確認 | `GET /gift-cards/{code}/balance` |
| 利用 | `POST /gift-cards/{code}/redeem`（amount_cents, order_id） |
| 利用履歴 | `GET /gift-cards/{code}/transactions` |
| 失効処理 | `POST /admin/gift-cards/expire`（期限切れを一括失効） |
| コード検証 | `GET /gift-cards/{code}/validate` |
| 統計 | `GET /admin/gift-cards/stats`（発行総額・利用率・失効数） |

ポイント: 残高の正確な管理（overdraft 防止）、ユニークコード生成、失効処理、一括発行。

---

## Persona A — 大島 めぐみ（新卒・女性・22 歳）

### 背景

流通系専門学校卒 1 年目。コンビニやスーパーのギフトカードを使ったことはある。

### 作業シナリオ

1. `gift_cards(id, code, balance_cents, initial_balance_cents, expires_at, status)` テーブル。
2. コード生成を `str_pad(mt_rand(0, 9999), 16, '0')` でランダムに生成。推測可能・衝突リスクあり。
3. 残高の利用を `SET balance = balance - amount WHERE code=?` でトランザクションなし。
4. 一括発行をループで 1 件ずつ INSERT（パフォーマンス問題あり）。
5. 失効処理は `WHERE expires_at < now() AND status='active'` を `UPDATE status='expired'` で実装。

### ハマりポイント

- **コードの推測可能性**: `mt_rand()` はシード予測可能。`random_bytes()` が正解。
- **残高マイナス防止**: `WHERE balance >= amount` の条件なしで負の残高になり得る。
- **一括 INSERT**: ループ vs `INSERT INTO ... VALUES (),()`（マルチバリュー INSERT）の選択。

### 解決策 & 感想

セキュアコード生成と残高チェック付き UPDATE（`WHERE balance >= amount AND id = ?`）に修正。

> 「mt_rand は危険って知らなかった。
>  ギフトカードのコードって推測されたら困るから
>  random_bytes 使うべきだと言われた。
>  overdraft 防止のための WHERE 条件、考えてなかった。」

### DX スコア: ⭐⭐（2/5）

セキュリティ問題とオーバードラフト防止で大幅修正。コード生成の howto が必要。

---

## Persona B — 田澤 省吾（ロースキル・男性・40 歳）

### 背景

小売業の IT 担当 14 年。ポイントカードシステムの運用経験あり。

### 作業シナリオ

1. `gift_cards(id, code, balance_cents, expires_at, status)` + `gift_card_transactions(card_id, amount_cents, type, order_id, created_at)` テーブル（ledger 方式）。
2. コード生成: `strtoupper(bin2hex(random_bytes(8)))` で 16 文字のランダム英数字。
3. 利用処理（トランザクション内）:
   ```sql
   UPDATE gift_cards SET balance_cents = balance_cents - :amount
   WHERE code=:code AND balance_cents >= :amount AND status='active' AND expires_at > now()
   ```
   影響行数 0 なら「残高不足または無効」422。
4. 一括発行は `INSERT INTO ... VALUES (),(),()`（マルチバリュー INSERT）。
5. 統計クエリは GROUP BY で集計。

### ハマりポイント

- **マルチバリュー INSERT のパラメータ**: `VALUES (?,?,?), (?,?,?),...` のプレースホルダーを
  動的に生成する書き方が分からなかった（`implode()` で解決）。
- **コード重複チェック**: 一括発行でコードが重複した場合の再生成ロジック。
  `UNIQUE(code)` 制約を設けて INSERT 失敗時に再試行。
- **失効後の残高**: 失効したカードの残高を会計上どう処理するか（「失効収益」として記録するか）。

### 解決策 & 感想

実用的に完成。マルチバリュー INSERT の書き方は Stack Overflow で確認した。

> 「マルチバリュー INSERT のプレースホルダー生成は
>  howto があれば速かった。
>  失効収益の記録は今回省略したけど、業務では必要。」

### DX スコア: ⭐⭐⭐（3/5）

良好に完成。マルチバリュー INSERT と一括処理パターンの howto が欲しい。

---

## Persona C — 岩瀬 千寿（シニア・女性・44 歳）

### 背景

フィンテック系スタートアップの支払いエンジニア 14 年。決済システムの冪等性に精通。

### 作業シナリオ

1. `gift_cards` + `gift_card_transactions(id, card_id, amount_cents, type, reference_id, created_at)` — `reference_id` で冪等性管理。
2. コード生成: `bin2hex(random_bytes(8))` + Luhn チェックデジット（簡易版）。
3. 利用処理の冪等性: `reference_id` = `order_id`。同一 `order_id` で 2 回リデームしようとすると
   「すでに処理済み」422 を返す（`gift_card_transactions` の UNIQUE(reference_id) で検出）。
4. 残高は `gift_card_transactions` から `SUM()` で計算（スナップショットも同時更新）。
5. 一括発行は `InsertBatchUseCase` として実装。1000 件ずつ分割して INSERT。

### ハマりポイント

- **冪等性の実装**: `UNIQUE(reference_id)` + INSERT 失敗時のエラー判別（重複 vs その他エラー）。
- **Luhn チェックデジット**: 実装が複雑なため今回は省略して `bin2hex` のみに留めた。
- **大量一括発行のタイムアウト**: 10000 件の発行をリクエスト 1 回でやろうとするとタイムアウト。
  分割処理またはバックグラウンド実行が必要。

### 解決策 & 感想

高品質で完成。冪等性の実装パターンは NENE2 howto にほしい内容。

> 「冪等性は支払い系では必須。UNIQUE(reference_id) パターンは
>  ギフトカードだけでなく全ての支払い処理で使える。
>  howto に入れる価値が高いと思う。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。冪等性実装パターンと一括処理のスケーリング howto が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 大島（新卒） | △ セキュリティ問題あり | 2/5 | セキュアコード生成、overdraft 防止 |
| 田澤（ロースキル） | ○ 実用的完成 | 3/5 | マルチバリュー INSERT、コード重複対策 |
| 岩瀬（シニア） | ◎ 高品質完成 | 4/5 | 冪等性実装、大量一括処理スケーリング |

**共通のフリクション**:
1. **セキュアトークン/コード生成** — `random_bytes()` vs `mt_rand()` の説明（複数シナリオで言及）。
2. **冪等性実装パターン** — `UNIQUE(reference_id)` による重複処理防止。支払い系で必須。
3. **マルチバリュー INSERT と一括処理** — プレースホルダー動的生成と分割処理パターン。
