# DX Scenario 23: フリーランス見積もり

## アプリ概要

案件・見積書・承認・請求書を管理するフリーランス業務支援 API。

| 機能 | エンドポイント例 |
|------|----------------|
| 案件管理 | `GET /projects`, `POST /projects`（client_name, description） |
| 見積書作成 | `POST /projects/{id}/quotes`（valid_until, line_items: [{name, qty, unit_price}]） |
| 見積書確認 | `GET /quotes/{id}`（合計・税込み金額含む） |
| 承認依頼 | `POST /quotes/{id}/send`（メール通知想定）|
| 承認 | `POST /quotes/{id}/approve`（client_token） |
| 却下 | `POST /quotes/{id}/reject`（reason） |
| 請求書生成 | `POST /quotes/{id}/invoice`（見積承認後のみ）|
| 請求書一覧 | `GET /invoices?status=unpaid` |

ポイント: 明細行の合計計算（小計・税・合計）、状態遷移（draft→sent→approved/rejected）、承認トークン。

---

## Persona A — 藤田 智也（新卒・男性・25 歳）

### 背景

経営学部卒→システム会社入社 1 年目。フリーランスの知人から「見積ソフトを作ってほしい」と頼まれた経験あり（Excel で対応した）。

### 作業シナリオ

1. `quotes` テーブルを作成。明細行を `quotes.items_json TEXT` に JSON で保存。
2. 合計計算は PHP で JSON をデコードして計算。
3. 承認トークンを `quotes.token = md5(quote_id)` で生成（推測可能）。
4. 税率を「固定 10%」でハードコード。
5. 見積書から請求書への「コピー」機能を実装できず省略。

### ハマりポイント

- **明細行の正規化**: JSON カラムでは後から「単価を更新する」「明細を追加する」ができない。
- **セキュアなトークン生成**: `md5(quote_id)` は予測可能でセキュリティ上問題。
- **税率の変更対応**: 税率が変わった場合に過去の見積書の計算が変わってしまう（レート固定すべき）。

### 解決策 & 感想

`quote_line_items(quote_id, name, qty, unit_price_cents, order_index)` テーブルに変更。
トークンは `bin2hex(random_bytes(32))` に修正。

> 「JSON カラムは楽だけど後で困るのは分かってきた。
>  税率を毎回計算するか保存するかは先輩に聞いた。
>  『税率は承認時の値で固定』って当然のことだと言われた。」

### DX スコア: ⭐⭐⭐（3/5）

改善後は良好。金額設計パターンの howto（整数/税率固定）が欲しい。

---

## Persona B — 黒田 愛子（ロースキル・女性・33 歳）

### 背景

フリーランス Web デザイナー → エンジニア転向 4 年目。見積書の業務知識あり（自分でも使う）。

### 作業シナリオ

1. テーブル設計:
   - `quotes(id, project_id, status, tax_rate, valid_until, token, sent_at, decided_at)`
   - `quote_line_items(id, quote_id, name, qty, unit_price_cents, order_index)`
2. 合計計算を SQL で:
   ```sql
   SELECT SUM(qty * unit_price_cents) AS subtotal_cents FROM quote_line_items WHERE quote_id=?
   ```
   税込み合計 = `(int)(subtotal_cents * (1 + tax_rate))` を PHP で計算。
3. 承認トークンは `bin2hex(random_bytes(32))` で生成。
4. 状態遷移を `state-machine-workflow-api.md` のパターンで実装。
5. 請求書は `invoices` テーブルを新規作成し、見積書の明細をコピー INSERT。

### ハマりポイント

- **金額の整数演算**: `unit_price_cents` の整数 × `qty` 整数は良いが、
  税額計算 `subtotal * 1.1` で小数が出る。`intdiv()` vs `round()` の選択。
- **見積書承認後の変更防止**: `approved` ステータス後に明細を変更できないよう
  UseCase でチェックを入れる必要があった。
- **トークンの有効期限**: `valid_until` は見積書全体の有効期限。
  承認トークンは使い切り（1 回使ったら無効化）の仕様にした。

### 解決策 & 感想

業務知識でスムーズに設計。整数演算の丸め方は `intdiv()` で切り捨てに統一。

> 「税額の計算って実は複雑。切り捨てにしたけど、
>  業界標準ってあるのかな。PHPで intdiv か round かは
>  howto に方針が書いてあれば迷わなかった。」

### DX スコア: ⭐⭐⭐（3/5）

良好に完成。金額計算の丸め方針と承認後変更防止のパターンが欲しい。

---

## Persona C — 近藤 大輔（ベテラン・男性・46 歳）

### 背景

会計系 SaaS の開発リード 18 年。「金額計算はセント単位で整数、丸めは最後に 1 回だけ」が鉄則。

### 作業シナリオ

1. テーブル設計（金額は全てセント整数）:
   - `quotes(id, project_id, status, tax_rate_bps, valid_until, approval_token, created_at)`
   - `tax_rate_bps`: 税率を basis point で管理（10% = 1000 bps）
   - `quote_line_items(id, quote_id, description, quantity, unit_price_cents, subtotal_cents, display_order)`
   - `subtotal_cents = quantity * unit_price_cents` — INSERT/UPDATE 時に計算して保存
   - `invoices(id, quote_id, subtotal_cents, tax_cents, total_cents, status, due_date, issued_at)`
2. 合計計算: `tax_cents = ROUND(subtotal * tax_rate_bps / 10000.0)` で 1 回だけ丸め。
3. 承認後の変更防止: `QuoteRepository::canEdit(int $id)` で status チェック。
4. 承認トークン: `bin2hex(random_bytes(32))` + `token_used_at` で使い切り管理。
5. 請求書生成: `InvoiceGeneratorUseCase` が見積書から `invoices` レコードを作成。
   見積書の明細は変更可能だが、請求書発行後は分離。

### ハマりポイント

- **`tax_rate_bps` の計算**: 1000 bps = 10% の計算を徹底。PHP でオーバーフローしない整数範囲の確認。
- **`subtotal_cents` の保存**: 行ごとに保存するか計算するかで迷い、「保存する（INSERT/UPDATE 時に計算）」を選択。
- **承認後の見積書 immutability**: 承認済みの見積書を「変更不可」にすると、
  クライアントが値引き交渉した際の対応（新しいバージョンの見積書を作る）が必要になると気づいた。

### 解決策 & 感想

高品質で完成。見積書のバージョン管理は要件拡張で追加予定。

> 「tax_rate_bps（ベーシスポイント）は過剰かもしれないけど、
>  税率変更時の対応が楽になる。
>  見積書バージョン管理（改訂版 v1.1, v1.2）は
>  多くのビジネスで必要になるパターンなので howto があると嬉しい。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。金額計算ポリシーと見積書バージョン管理のドキュメントが欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 藤田（新卒） | ○ 設計改善後完成 | 3/5 | 明細行の正規化、税率固定、セキュアトークン |
| 黒田（ロースキル） | ○ 良好に完成 | 3/5 | 整数演算の丸め方針、承認後変更防止 |
| 近藤（ベテラン） | ◎ 高品質完成 | 4/5 | basis point 税率、見積書バージョン管理 |

**共通のフリクション**:
1. **金額計算の整数演算ポリシー** — セント単位・丸め方針の標準ガイドライン（複数シナリオで言及）。
2. **承認後の immutability パターン** — 承認済みレコードの変更防止と改訂バージョン管理。
3. **セキュアトークン生成** — `random_bytes(32)` の使い切り管理パターン（複数シナリオで言及）。
