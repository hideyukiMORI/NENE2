# DX Scenario 11: サブスクリプション管理

## アプリ概要

プラン・契約・更新・解約・請求を管理するサブスクリプション API。

| 機能 | エンドポイント例 |
|------|----------------|
| プラン管理 | `GET /plans`, `POST /plans`（name, price, billing_cycle） |
| 契約 | `POST /subscriptions`（user_id, plan_id, start_at） |
| 契約状態 | `GET /subscriptions/{id}`（active/cancelled/expired） |
| 更新 | `POST /subscriptions/{id}/renew` |
| 解約 | `POST /subscriptions/{id}/cancel`（immediate vs end_of_period） |
| プラン変更 | `POST /subscriptions/{id}/change-plan`（plan_id, effective_at） |
| 請求履歴 | `GET /subscriptions/{id}/invoices` |
| 期限切れ一覧 | `GET /subscriptions?status=expired` |

ポイント: 契約ライフサイクル（active→cancelled/expired）、即時解約 vs 期末解約、プラン変更の日割り計算。

---

## Persona A — 安藤 拓哉（新卒・男性・24 歳）

### 背景

情報系大学卒 1 年目。課金系サービスの実装は完全初体験。「Stripe は聞いたことある」レベル。

### 作業シナリオ

1. `subscriptions` テーブルを作成。`status = TEXT DEFAULT 'active'`。
2. 「解約」は `DELETE /subscriptions/{id}` で物理削除にしてしまう（履歴が消える）。
3. 「更新」を「新しいサブスクリプションを作る」と誤解し、ユーザーごとに複数のアクティブな
   契約が存在できる設計になる。
4. 請求履歴テーブル（`invoices`）を忘れる。
5. 「期末解約」の概念（契約期間が終わるまで使える）が理解できず、即時解約のみ実装。

### ハマりポイント

- **課金ライフサイクルの理解**: 「解約 = 削除」ではなく「解約 = ステータス変更」。
- **1 ユーザー 1 アクティブ契約**: `UNIQUE(user_id) WHERE status='active'` 制約が必要。
- **期末解約**: `cancel_at` (将来日時) と `cancelled_at` (過去日時) の使い分け。

### 解決策 & 感想

「サブスクリプションのライフサイクル」を先輩に説明してもらいドキュメント化した。
`soft-delete-restore-permanent.md` の考え方（ステータス変更）を適用した。

> 「解約しても『消す』じゃないんだ、って最初理解できなかった。
>  こういうビジネスロジックって学校では教えてくれないんだよね。」

### DX スコア: ⭐⭐（2/5）

課金ドメイン知識の欠如で設計全体を見直し必要。ライフサイクル図の howto が欲しい。

---

## Persona B — 今井 薫（ロースキル・女性・32 歳）

### 背景

SaaS プロダクトのカスタマーサポート担当からエンジニアに転向 3 年目。
Stripe のダッシュボードを日常的に使っており、課金の概念は業務知識あり。

### 作業シナリオ

1. `plans` / `subscriptions` / `invoices` テーブルを設計。
   `subscriptions.cancel_at` (期末解約日) と `cancelled_at` (即時解約日時) を分けて管理。
2. ステータス計算:
   - `status = 'active'`: `current_period_end > now()` AND `cancelled_at IS NULL`
   - `status = 'cancelled'`: `cancel_at IS NOT NULL` AND `cancel_at > now()`（まだ使える）
   - `status = 'expired'`: `current_period_end < now()`
3. プラン変更は `effective_at` が今日以降なら新しいレコードを追加（将来変更予約）。
4. 請求は `invoices(subscription_id, amount, billing_date, status)` テーブルで管理。
5. `GET /subscriptions?status=expired` で期限切れ一覧。

### ハマりポイント

- **ステータスの計算**: `status` カラムを持つか計算で返すかで迷い、最終的に「計算して返す」方式に。
   ただし SQL で `WHERE status='active'` と書けないのが不便。
- **プラン変更の日割り計算**: 月次プランの途中変更の料金をどう計算するか。今回は省略。
- **`GET /subscriptions` のページネーション**: ステータス計算を SQL にどう組み込むか複雑。

### 解決策 & 感想

業務知識でスムーズに設計できた。ステータス計算の SQL 書き方は自力で解決。

> 「ステータスを DB に保存するか計算するかって悩む。
>  計算の方が正確だけど WHERE で絞れないのが辛い。
>  materialized column とかどうやるんだろう。」

### DX スコア: ⭐⭐⭐（3/5）

良好に完成。計算ステータスのパフォーマンス問題と日割り計算の実例が欲しい。

---

## Persona C — 桑原 修一（シニア・男性・46 歳）

### 背景

SaaS スタートアップの元 CTO。Stripe / Braintree の API 統合実績多数。
「Stripe は使えない環境での純粋な課金ロジック実装」として挑む。

### 作業シナリオ

1. `plans(id, name, price_cents, billing_cycle_days)` — 価格は整数（セント）。
2. `subscriptions(id, user_id, plan_id, current_period_start, current_period_end, status, cancel_at, cancelled_at)`.
   `status` カラムに値を保存しつつ、更新クエリで常に同期。
3. 状態遷移を `Subscription::isActive()` / `isCancellable()` / `isExpired()` で管理。
4. プラン変更は `pending_plan_changes(subscription_id, new_plan_id, effective_at)` テーブルで予約管理。
   日割り計算: `days_used / billing_cycle_days * old_price + remaining_days / new_cycle * new_price`。
5. `InvoiceGenerator::generate()` UseCase で請求書生成。

### ハマりポイント

- **価格の整数管理**: PHP の `int` で金額計算する際の丸め方針（切り捨て vs 銀行家の丸め）。
- **`status` カラムと計算の二重管理**: カラムに保存した `status` を実際の日時から
  「修正する」バッチが必要になる設計上の問題。
- **pending_plan_changes の適用**: 「効力発生日になったら変更を適用する」処理を
  NENE2 でどう実装するか（バッチ非対応）。

### 解決策 & 感想

機能的には完成。バッチ処理はモックエンドポイント（`POST /admin/apply-pending-changes`）で代替。

> 「課金の整数演算は要注意。PHP で floor vs round の使い分けは
>  ドキュメントに書いておくべきポイントだと思う。
>  NENE2 にバッチ・定期実行の仕組みがないのは想定内だけど、
>  'こう代替する' というパターンの howto があると嬉しい。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。整数演算ポリシーとバッチ代替パターンの howto が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 安藤（新卒） | △ 大幅再設計必要 | 2/5 | 課金ライフサイクル理解、ソフトデリート概念 |
| 今井（ロースキル） | ○ 良好に完成 | 3/5 | 計算ステータスのパフォーマンス、日割り計算 |
| 桑原（シニア） | ◎ 高品質完成 | 4/5 | 整数演算ポリシー、バッチ代替パターン |

**共通のフリクション**:
1. **課金ライフサイクルの説明** — active/cancelled/expired のライフサイクル図 howto。
2. **計算ステータス vs 保存ステータス** — パフォーマンスとトレードオフの howto。
3. **整数（セント）演算ポリシー** — 金額計算の丸め方針を明示したドキュメント。
