# DX Scenario 38: フードデリバリー注文

## アプリ概要

レストラン・メニュー・カート・注文・配達状況を管理するフードデリバリー API。

| 機能 | エンドポイント例 |
|------|----------------|
| レストラン | `GET /restaurants?area=渋谷&category=ラーメン`, `GET /restaurants/{id}` |
| メニュー | `GET /restaurants/{id}/menu`, `POST /restaurants/{id}/menu-items` |
| カート | `POST /carts`（session/user based）, `POST /carts/{id}/items` |
| 注文 | `POST /orders`（cart_id, delivery_address, payment_method） |
| 配達追跡 | `GET /orders/{id}/tracking`（準備中→集荷→配達中→完了）|
| 注文履歴 | `GET /users/{id}/orders?page=1` |
| レストラン注文一覧 | `GET /restaurants/{id}/orders?status=preparing` |
| 評価 | `POST /orders/{id}/review`（rating, comment） |

ポイント: カートの一時保存（セッションまたは DB）、注文確定の在庫確認、配達ステータス遷移。

---

## Persona A — 長谷部 光（新卒・男性・23 歳）

### 背景

情報系大学卒業直後。UberEats を毎日使う。「簡単に作れそう」と思っていた。

### 作業シナリオ

1. `restaurants` / `menu_items` / `orders` テーブルを作成。
2. カートを省略して「直接 `orders` に注文を入れる」設計にした。
3. メニューアイテムの「在庫なし」状態を管理しない（常に注文可能）。
4. 注文ステータスを自由に変更できる設計。
5. 配達追跡を `orders.status TEXT` の単純更新で実装（履歴なし）。

### ハマりポイント

- **カートの意味**: 「カートがなぜ必要か」（仮注文 → 確認 → 確定のフロー）を理解していなかった。
- **在庫なし状態**: `menu_items.is_available BOOLEAN` が必要。
- **状態遷移ルール**: 「配達完了 → 準備中」という逆行が可能な設計。

### 解決策 & 感想

`carts` テーブルと `cart_items` テーブルを追加。ステータス遷移は `state-machine-workflow-api.md` を参照。

> 「カートって実は複雑な概念なんだ。
>  ショッピングカートのシナリオ読んでたら少し理解できてたかも。
>  state-machine howto がここでも使えた。」

### DX スコア: ⭐⭐⭐（3/5）

シナリオ間の知識連携あり。カートの役割の説明が howto に欲しい。

---

## Persona B — 深津 裕子（ロースキル・女性・30 geq 歳）

### 背景

飲食系 EC サイトの運用担当 7 年。食べログ/UberEats の管理者経験あり。

### 作業シナリオ

1. テーブル設計:
   - `restaurants(id, name, category, area, min_order_yen, delivery_fee_yen, status: open/closed/busy)`
   - `menu_items(id, restaurant_id, name, price_yen, description, is_available, category)`
   - `carts(id, user_id, restaurant_id, created_at)` + `cart_items(cart_id, menu_item_id, qty)` UNIQUE(cart_id, menu_item_id)
   - `orders(id, user_id, restaurant_id, delivery_address, total_yen, status, created_at)`
   - `order_items(order_id, menu_item_id, qty, price_at_order_yen)` — 注文時の価格を固定
   - `order_status_log(order_id, status, changed_at)` — 追跡履歴
2. 注文確定時: カート内のメニューが全て `is_available=1` か確認 → `orders` + `order_items` INSERT → カート削除。
3. 価格は注文時点で `price_at_order_yen` に固定（メニュー値上げの影響を受けない）。
4. 配達追跡: `order_status_log` に全ステータス変更を記録。
5. レストランが閉店中（`status='closed'`）の場合は注文を 422 で拒否。

### ハマりポイント

- **カートと注文の分離**: カート確定時にカートを削除するが、削除前に `order_items` に
  コピーすることを忘れそうになった。
- **同一レストランのカート制限**: 複数レストランから混在して注文できるか？
  今回は「1 カート = 1 レストラン」に制限。
- **配達员の管理**: 今回は省略（レストランから直接配達する設計）。

### 解決策 & 感想

業務知識で良好に設計できた。価格固定の設計（`price_at_order_yen`）は重要な判断だった。

> 「注文時の価格を固定するのは当然の設計だけど、
>  初心者は menu_items.price を直接参照してしまう。
>  howto に『注文時点の価格を保存する理由』を書いてほしい。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。注文時価格固定の設計説明が欲しい。

---

## Persona C — 日高 祐介（シニア・男性・41 geq 歳）

### 背景

フードデリバリープラットフォーム開発 12 年。Uber Eats 的なシステムの設計経験あり。

### 作業シナリオ

1. テーブル設計（スケーラビリティ重視）:
   - `restaurants` + `operating_hours(restaurant_id, day_of_week, open_time, close_time)` — 営業時間管理
   - `carts` + `cart_items` + `orders` + `order_items(price_at_order_cents, tax_at_order_cents)`
   - `delivery_tracking(order_id, status, latitude, longitude, updated_at)` — GPS 追跡（GPS 省略版として `location_description`）
2. 注文確定はトランザクション内で:
   - 各メニュー `is_available` 確認
   - レストランの `status = 'open'` 確認
   - 合計金額計算（`price * qty` の SUM）
   - `minimum_order_yen` 以上か確認
   - `orders` + `order_items` INSERT
   - カート削除
3. 営業時間チェック: `day_of_week = strftime('%w', 'now')` + 時刻範囲で営業中かどうか確認。
4. 配達追跡は `state-machine-workflow-api.md` のパターンを活用。
5. 注文のキャンセル: `prepared` ステータスからはキャンセル不可（`preparing` まで可）。

### ハマりポイント

- **`strftime('%w', 'now')`**: SQLite の曜日番号（0=日曜）を確認。`CASE WHEN 0 THEN 'Sunday'` 等でマッピング。
- **営業時間の判定**: `open_time < time('now') < close_time` のシンプルな実装だが、
  深夜営業（22:00〜02:00）の日付またがりケースが複雑。
- **最小注文金額**: `cart_total < restaurant.min_order_yen` の場合の 422 エラー、
  誰もが最初に忘れる要件。

### 解決策 & 感想

高品質で完成。深夜営業の日付またがりは「今回は省略」として残した。

> 「深夜営業の時間範囲チェックは SQLite だと面倒。
>  `open_time <= close_time` なら普通の BETWEEN、
>  `open_time > close_time` なら 2 つの範囲の OR 条件が必要。
>  こういう営業時間チェックパターンの howto が欲しい。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。営業時間チェックパターンと深夜営業の日付またがり処理の howto が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 長谷部（新卒） | ○ カート設計を習得 | 3/5 | カートの役割理解、在庫なし状態 |
| 深津（ロースキル） | ○ 実用的完成 | 3/5 | 注文時価格固定の説明 |
| 日高（シニア） | ◎ 高品質完成 | 4/5 | 営業時間チェック、深夜営業の日付またがり |

**共通のフリクション**:
1. **注文時点の価格固定** — 多くの EC アプリで必要な「注文時点の価格を保存する理由」の説明。
2. **営業時間チェックパターン** — `operating_hours` テーブル + 時刻範囲チェックの howto。
3. **状態遷移 howto の発見性** — `state-machine-workflow-api.md` は EC 注文でも活用されているが、発見パスが必要。
