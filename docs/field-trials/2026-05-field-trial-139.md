# Field Trial 139 — ゲスト注文（カート → 注文 → 明細）

**Date**: 2026-05-21  
**App**: `orderlog`  
**Path**: `/home/xi/docker/NENE2-FT/orderlog/`  
**NENE2 version**: ^1.5  
**Release**: v1.5.73  
**Special**: なし（通常 FT）

---

## What was built

カートに商品を追加し、注文として確定する E コマース基本フローを実装した。

| Endpoint | 説明 |
|---|---|
| `POST /users` | ユーザー作成 |
| `POST /products` | 商品作成（name, price, stock） |
| `POST /cart` | カートに商品追加（同一商品は数量加算） |
| `GET /cart` | カート内容確認（合計金額付き） |
| `DELETE /cart/{productId}` | カートから商品削除 |
| `POST /orders` | 注文確定（在庫チェック → 在庫減算 → カートクリア） |
| `GET /orders/{orderId}` | 注文詳細取得（明細 + 合計、所有者のみ） |

---

## Architecture decisions

### price snapshot を order_items に持つ

注文確定時に `products.name` と `products.price` を `order_items` にコピーする。これにより価格改定後も過去の注文金額が正確に保存される。`GET /orders/{orderId}` は `order_items.price` を返す（`products.price` ではない）。

### 在庫チェックは先にまとめて実施

複数商品がカートにある場合、一部だけ在庫不足で途中でロールバックするのは複雑。全商品を事前に検証し、全て在庫あることを確認してから一括で在庫減算・注文作成を行う。

### カートの数量加算

`UNIQUE (user_id, product_id)` 制約でカートの重複行を防ぐ。同一商品を再度追加すると INSERT ではなく `quantity = quantity + N` の UPDATE になる。

### カートは user_id で完全分離

`cart_items` の全クエリに `WHERE user_id = ?` が付く。他ユーザーのカートは参照できない。

---

## Test results

| Suite | Tests | Result |
|---|---|---|
| `OrderTest.php` | 23 | Pass |
| **Total** | **23** | **Pass** |

---

## Developer Experience (DX) Review

### Persona 1 — 初心者 Web 開発者（PHP 学習中）

「カートと注文とアイテムで3テーブル（cart_items, orders, order_items）に分かれているのが最初は難しく感じた。でも order_items に name と price をコピーする理由（価格スナップショット）を理解したら納得できた。在庫チェックを先にまとめてやる設計も、失敗時のロールバックが複雑になるという説明で理解できた。addToCart の UPDATE ロジックが少しわかりにくかった。」

★★★☆☆ — 複数テーブルの役割が最初は混乱しやすい

### Persona 2 — Laravel 経験者（NENE2 初学）

「Laravel なら Cart モデルと Order モデルを Eloquent で作れば自動的にリレーション管理できる。NENE2 では JOIN クエリを自分で書く必要があった。でも Repository に集約されているのでコードの流れは追いやすい。`addToCart()` が既存チェックと INSERT/UPDATE を1メソッドで扱っているのはシンプルで好き。PHPStan level 8 で `array_sum` の型が通るかどうか少し心配だったが問題なかった。」

★★★★☆ — 生 SQL だが Repository 内で整理されており読みやすい

### Persona 3 — セキュリティエンジニア

「在庫チェックは全商品を先に検証してから減算するので、レースコンディションのウィンドウは残る（並行リクエストで在庫が二重減算される可能性）が、単一サーバーの FT スコープでは許容範囲。本番ではトランザクション + SELECT FOR UPDATE が必要。注文詳細の GET は `order['user_id'] !== $actorId` で所有者チェックしているので IDOR は防がれている。X-User-Id ヘッダーは認証なしのため、本番ではベアラートークンに置き換えが必須。」

★★★☆☆ — FT 実装として基本的な安全性は確保。本番化には認証とトランザクション追加が必要

### Persona 4 — フロントエンド開発者（API 利用者）

「カートの `GET /cart` が `items` + `count` + `total` を一度に返してくれるのはフロント側でとても使いやすい。注文確定時に `total` がレスポンスに含まれるのも良い。在庫不足の 422 レスポンスに `product_id` が含まれているのでどの商品が問題かすぐわかる。DELETE /cart/{productId} が 204 を返すのは HTTP の作法として正しい。」

★★★★★ — API レスポンス設計がフロント視点で使いやすい

### Persona 5 — インフラ・DevOps エンジニア

「SQLite のみの実装なのでデプロイは簡単。テストが23件あり、在庫減算・カートクリア・複数商品など重要なフローをカバーしている。`array_sum` と `array_map` の組み合わせは PHP 標準なので依存パッケージなし。スキーマが5テーブルと少し複雑になってきたが、`schema.sql` 1ファイルに集約されているのは管理しやすい。本番では stock カラムのマイナス防止に DB レベルの CHECK 制約も検討したい。」

★★★★☆ — シンプルな構成で運用しやすい。本番向けには CHECK(stock >= 0) を追加推奨

### Persona 6 — プロダクトマネージャー

「カート → 注文の基本フローが実装されている。在庫切れは 422 で正確に応答し、どの商品が問題かわかる。注文確定後にカートがクリアされるのは当然の UX。価格スナップショットが注文明細に保存されるのは重要で、返金・問い合わせ対応時に必要。次のステップとして注文キャンセル・注文一覧・ゲスト注文（ユーザー登録なし）などを追加できる。」

★★★★☆ — E コマースの核となる機能が揃っている。拡張性の高い設計

---

## Howto

`docs/howto/guest-order-system.md`
