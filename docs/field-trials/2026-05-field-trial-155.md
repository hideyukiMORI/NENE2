# Field Trial 155 — ショッピングカート (cartlog)

**Date**: 2026-05-21
**Release**: v1.5.89
**Project**: `/home/xi/docker/NENE2-FT/cartlog/`
**Issue**: #814

---

## テーマ

ショッピングカート API の実装。カートへの商品追加・数量変更・削除・合計金額計算を REST API として提供する。

---

## 実装サマリー

| 項目 | 内容 |
|---|---|
| DB | users / products / cart_items（UNIQUE: user_id+product_id） |
| エンドポイント | GET /cart・POST /cart/items・PUT /cart/items/{id}・DELETE /cart/items/{id}・DELETE /cart |
| テスト | 28 tests / 47 assertions — 全通過 |
| 静的解析 | PHPStan level 8 — エラーなし |
| CS | php-cs-fixer — 準拠 |

---

## 設計上の意思決定

### 価格スナップショットなし

カートは揮発性（注文前のバッファ）として扱い、価格は products テーブルから都度取得する。注文確定時にスナップショットを取るパターンは別 FT（FT139 orderlog）が担当する。

### POST 冪等性（数量加算）

同一商品を再度 POST した場合は数量を加算して 200 を返す（新規は 201）。これにより「同じ商品を誤って2回カートに入れた」という UX 混乱を防ぐ。

### quantity=0 で削除

PUT `/cart/items/{productId}` に `quantity: 0` を渡すと商品削除として扱い 204 を返す。DELETE エンドポイントとの一貫性を保ちつつ、クライアントが「数量をゼロにしたい」という直感的操作を可能にする。

### RuntimeApplicationFactory でエラーハンドリング

`Router` を直接返すと `ValidationException` が未キャッチになる。`RuntimeApplicationFactory` でラップすることで、エラーハンドリング・セキュリティヘッダー・バリデーション → 422 変換が自動で行われる。

---

## 発見した API の注意点

| API | 注意 |
|---|---|
| `JsonResponseFactory` | コンストラクタに `ResponseFactoryInterface` と `StreamFactoryInterface` の2引数が必要。`Psr17Factory` は両インターフェースを実装しているため1インスタンスを両方に渡せる |
| `DatabaseConfig` | コンストラクタの第1引数は `url`（nullable）、第2引数は `environment`（文字列）。SQLite では `host=''`・`port=1`・`charset=''` を渡す |
| `PdoDatabaseQueryExecutor` | コンストラクタは `DatabaseConnectionFactoryInterface` を取る（`PDO` 直接ではない） |
| クラス名 | `Nene2\Http\Psr17Factory` は存在しない → `Nyholm\Psr7\Factory\Psr17Factory` を使う |

---

## テストカバレッジ

```
GET /cart
  ✓ 認証なしで 401
  ✓ 存在しないユーザーで 404
  ✓ 空カートで items=[]・total=0・count=0

POST /cart/items
  ✓ 認証なしで 401
  ✓ 新規追加で 201、subtotal 正確
  ✓ 同一商品の再追加で 200、数量加算
  ✓ 不明ユーザーで 404
  ✓ 不明商品で 404
  ✓ body なしで 422
  ✓ quantity が文字列で 422
  ✓ quantity=0 で 422
  ✓ product_id が文字列で 422

GET /cart（items あり）
  ✓ 複数商品の合計金額正確
  ✓ ユーザー間でカートが独立

PUT /cart/items/{productId}
  ✓ 認証なしで 401
  ✓ カートにない商品で 404
  ✓ 数量変更で 200、新 subtotal 正確
  ✓ quantity=0 で削除→ 204、カート空
  ✓ quantity=-1 で 422
  ✓ quantity が文字列で 422

DELETE /cart/items/{productId}
  ✓ 認証なしで 401
  ✓ カートにない商品で 404
  ✓ 削除後 204、他商品は残存、合計更新

DELETE /cart
  ✓ 認証なしで 401
  ✓ クリア後 204、カート空
  ✓ 他ユーザーのカートは影響なし

合計計算
  ✓ 3商品の合計が正確
  ✓ 削除後の合計更新
```

---

## Developer Experience (DX) Review

### ペルソナ 1 — 初学者（PHP を学んで3か月の大学生）

「カートに商品を追加する POST エンドポイントのコードを見たとき、`is_int($body['quantity'])` で型を確認しているのに気づきました。最初は『int を送ったのになぜ弾かれるのか？』と戸惑いましたが、JSON で `"quantity": 2` と文字列で送っていたせいだと分かりました。エラーメッセージに `quantity must be an integer` と明示されているので、すぐ原因を特定できました。`parseAddBody` の return 型が `array{int, int, list<ValidationError>}` というタプル形式も、慣れると PHPStan が守ってくれるので安心感があります。`quantity=0` で削除されるのは直感的で面白いと思いました。」

**感情体験**: 最初の型エラーで詰まったが、エラーメッセージが明確で数分で解決できた。達成感を得やすい設計。

---

### ペルソナ 2 — フロントエンド開発者（React/TypeScript 経験者、PHP 初心者）

「`GET /cart` のレスポンスに `items`・`total`・`count` が全部入っているのが嬉しい。フロントから3回叩く必要がなくてシンプルに使えます。`added_at`・`updated_at` が ISO 8601 で返ってくるので TypeScript 側の `Date` 変換も問題なし。`product_name` や `price` が items の中に入っているので商品情報の追加 API 呼び出しが不要。ただ価格スナップショットがないのは、商品が値下げされた場合にカートの合計が変わることを意味するので、UX 上の考慮が必要です。実装者に確認して、カート確定前に合計を再計算する処理を追加しました。」

**感情体験**: レスポンス設計が使いやすく、型安全に TypeScript から扱えた。価格変動の考慮はドキュメントに書いてほしかった。

---

### ペルソナ 3 — バックエンドエンジニア（Laravel 経験者、NENE2 初見）

「`RuntimeApplicationFactory` を使わずに `Router` を直接返すと `ValidationException` がミドルウェアで処理されず 500 になることに気づかずに最初 30 分ほどデバッグしました。howto に書いてあるので事前に読んでいれば防げた問題ですが、フレームワークがこの初期化パターンを強制してくれると嬉しい。`CartRepository::addItem` が内部で SELECT してから INSERT/UPDATE するのは、SQLite ではトランザクション外でも問題ないですが、高負荷環境では UPSERT に変えたほうが良いでしょう。`DatabaseQueryExecutorInterface` の `execute()` が `int`（rowCount）を返すことを知っていれば、`addItem` で INSERT/UPDATE を確認できますが、現実装では確認していない。許容範囲だと思います。」

**感情体験**: 慣れれば快適だが、`RuntimeApplicationFactory` の必要性がコンパイル時にわからないのが唯一の不満。

---

### ペルソナ 4 — セキュリティエンジニア

「`user_id` を `X-User-Id` ヘッダーのみから取得し、リクエストボディから受け取らない設計は正しい。これにより越権操作を防ぎ、他ユーザーのカートを操作するリクエストを構成できない。`quantity` のバリデーションで `is_int()` を使って文字列を明示的に拒否しているのは、型混乱攻撃への防御として適切。カートの合計は products テーブルから都度取得するため、カート価格の改ざん攻撃（malformed JSON で subtotal を書き換える）は成立しない。潜在的リスク: `product_id` が整数キャストで `0` になる場合のエラーハンドリングは、`$productId <= 0` チェックにより 404 を返す。SQLite の INTEGER PRIMARY KEY は 0 を有効値として扱わないので問題なし。`addItem` の SELECT→INSERT の非アトミック性は同一ユーザーからの並行リクエストで race condition の余地があるが、UNIQUE 制約があるため二重挿入は防がれる（ただし quantity 加算は失われる可能性がある）。本番では BEGIN EXCLUSIVE TRANSACTION を推奨。」

**感情体験**: 基本的なセキュリティパターンは良く押さえられている。並行性についてのコメントが howto にあると完璧。

---

### ペルソナ 5 — プロダクトマネージャー

「ショッピングカートとして必要な操作（追加・変更・削除・全削除・合計）がすべて揃っていて、MVP として完成度が高い。同一商品を追加したとき自動で数量加算される設計は EC サイトの標準的な挙動で正しい。`quantity=0` で削除という仕様は、フロントエンドの数量スピナーを直接 API に繋ぎやすいので良い設計です。ただし API ドキュメント（OpenAPI）がないため、フロントチームへの共有がやや手間。また、在庫チェック（stock）がカート追加時に行われていないため、在庫0の商品もカートに追加できてしまう点は次のフェーズで対応が必要です。」

**感情体験**: 機能的に満足。在庫チェック欠如は次スプリントのバックログに追加した。OpenAPI 対応を要望したい。

---

### ペルソナ 6 — DevOps / SRE

「`CartRepository::getCart` が `ORDER BY ci.added_at ASC, ci.id ASC` でソートしているのは良い。`added_at` が同秒になった場合も `id` でタイブレークするので決定的な順序が保証される。`addItem` で SELECT→UPDATE/INSERT の2クエリを発行しているため、カート操作の多いエンドポイントでは N+1 に近い問題が起きうるが、1ユーザーのカートに何十商品も入ることはないため実用上問題ない。スケールアウト時は `PdoConnectionFactory` の PDO インスタンスが毎リクエストで生成されるため、接続プーリングの仕組み（PgBouncer / ProxySQL）が外部に必要。テストが SQLite ベースで決定的・高速なのでCI パイプラインに組み込みやすい。」

**感情体験**: 安定したコードで運用負荷は少ない。接続プーリングについてのドキュメントがあると嬉しい。

---

## 次の FT

- **FT156**: 通常 FT + **脆弱性診断（VulnTest.php）** + **クラッカー攻撃試験（AttackTest.php）**（両サイクルが重なる）
- **FT157**: 通常 FT
- **FT158**: 通常 FT + MySQL 統合テスト
