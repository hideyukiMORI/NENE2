# Field Trial 18 — orderlog: トランザクション・バルク操作・アクセス制御の実地検証

## Date

2026-05-20

## Baseline

- NENE2 v1.5.1（`hideyukimori/nene2: ^1.5`、Packagist から取得）
- PHP 8.4
- プロジェクト: **orderlog** — 在庫管理付き注文 API
- エンティティ: `Product`（name, price, stock）、`Order`（total, status, items）、`OrderItem`（product_id, qty, unit_price）
- テスト: PHPUnit 15/15・PHPStan level 8・PHP-CS-Fixer（`--allow-risky=yes`）
- DB: SQLite（ファイルベース、テストごとに新規生成・削除）

## Goal

FT17（quotelog）ではカバーされなかった以下のパターンで摩擦を探す。

- **トランザクション**: 複数テーブルにわたる原子的操作（在庫デクリメント + 注文保存）
- **バルク操作**: 大量行の一括挿入エンドポイント
- **パス×メソッドの複合アクセス制御**: `GET /products` はパブリック、`POST /products` は API key 必須、`POST /orders` はパブリック

---

## 実装ログ

1. `composer require hideyukimori/nene2:^1.5` — v1.5.1 が Packagist に存在し、インストール成功。
2. Product ドメイン（`Product`, `ProductRepositoryInterface`, `SqliteProductRepository`, `ProductRouteRegistrar`, 例外・ハンドラ）を実装。
3. Order ドメイン（`Order`, `OrderItem`, `OrderRepositoryInterface`, `SqliteOrderRepository`, `CreateOrderUseCase`, `OrderRouteRegistrar`, 例外・ハンドラ）を実装。
4. `CreateOrderUseCase` でトランザクションパターンを実装 → F-1 を発見。
5. `public_html/index.php` でパス×メソッドのアクセス制御を設定 → F-2 を発見。
6. テスト 15 本を作成・全通過（15/15）。
7. PHPStan level 8: 0 エラー。
8. PHP-CS-Fixer: `--allow-risky=yes`（FT17 F-2 の対応済みドキュメントを参照して設定）。

---

## 摩擦記録

### F-1（高）: トランザクション内でリポジトリの DI インスタンスを再利用できない

**状況**: `DatabaseTransactionManagerInterface::transactional(callable $callback)` のコールバックは、
オープン中のトランザクションにバインドされた **新規の** `DatabaseQueryExecutorInterface` を受け取る。
コンストラクタで注入されたリポジトリは別の（非トランザクション用）エグゼキュータを保持しており、
コールバック内で安全に再利用できない。

```php
// CreateOrderUseCase::execute() 内
return $this->transactionManager->transactional(
    function (DatabaseQueryExecutorInterface $tx) use ($lineItems): Order {
        // F-1: コンクリートクラスをここで生成しなければならない。
        //      インターフェース注入済みインスタンスの再利用は不可。
        $products = new SqliteProductRepository($tx);
        $orders   = new SqliteOrderRepository($tx);
        // ...
    },
);
```

**影響**: 純粋なインターフェース依存を破る。コールバック内でコンクリートクラスを直接 `new` することで
ユースケースが実装詳細（`SqliteProductRepository` など）を知ることになる。
テストでモックに差し替えることも困難になる。

**期待する解決策**: NENE2 が `RepositoryFactory` などの抽象を提供し、
コールバックに渡されたエグゼキュータを受け取ってリポジトリを生成できるようにする。
あるいは `transactional()` がコールバック内で同一コネクションを共有する既存エグゼキュータを
「昇格」させる仕組みがあれば、注入済みインスタンスを再利用できる。

**Issue**: 要検討（新規 Issue として起票が必要）

---

### F-2（中）: `RuntimeApplicationFactory` のデフォルト `machineApiKeyProtectedPaths` がプレフィックス保護と競合

**状況**: `RuntimeApplicationFactory` は `machineApiKeyProtectedPaths` のデフォルト値として
`['/machine/health']` を持つ。`ApiKeyAuthenticationMiddleware` の内部ロジックは
`protectedPaths` が非空のとき **完全一致モード** で動作し、`protectedPathPrefixes` の評価をスキップする。

そのため `machineApiKeyProtectedPathPrefixes` と `machineApiKeyProtectedMethods` を組み合わせて
「`POST /products` のみ保護、`POST /orders` はパブリック」を実現しようとしても、
デフォルトの `protectedPaths` が残っていると期待通りに動作しない。

```php
// 誤り（machineApiKeyProtectedPaths のデフォルトが残っているため、prefix check が発火しない）
(new RuntimeApplicationFactory($psr17, $psr17,
    machineApiKey: 'key',
    machineApiKeyProtectedPathPrefixes: ['/products'],
    machineApiKeyProtectedMethods: ['POST', 'PUT', 'DELETE'],
))->create();

// 正しい（デフォルトを明示的にクリアする必要がある）
(new RuntimeApplicationFactory($psr17, $psr17,
    machineApiKey: 'key',
    machineApiKeyProtectedPaths: [],              // デフォルトを上書き
    machineApiKeyProtectedPathPrefixes: ['/products'],
    machineApiKeyProtectedMethods: ['POST', 'PUT', 'DELETE'],
))->create();
```

**影響**: `machineApiKeyProtectedPaths` のデフォルト値が非直感的な副作用を持つ。
テストなしでは気づきにくく、「プレフィックスを設定したのに効かない」という混乱を招く。

**期待する解決策**: 
- `machineApiKeyProtectedPaths` のデフォルトを `[]` に変更（破壊的変更）
- または、PHPDoc に競合について明記し、`machineApiKeyProtectedPaths: []` を明示的に渡す必要性を示す

**Issue**: 要検討

---

### F-3（低）: `RequestSizeLimitMiddleware` の上限が `RuntimeApplicationFactory` で設定不可

**状況**: `RuntimeApplicationFactory::create()` は内部で
`new RequestSizeLimitMiddleware($problemDetails)` をデフォルト 1MB でハードコードする。
factory にこの値を変更するパラメータがない。

大量インポートエンドポイント（今回は `POST /products/bulk`）では、
500 件×~60 バイト ≈ 30KB の実用ペイロードで問題は生じなかった。
ただし 1MB を超える CSVインポート代替APIや画像メタデータ一括登録などでは
`RuntimeApplicationFactory` を迂回するか、サイズ制限を下げる迂回策が取れない。

**期待する解決策**: `RuntimeApplicationFactory` に `requestMaxBodyBytes` パラメータを追加する。

---

## テストカバレッジ

| テスト | 検証内容 |
|---|---|
| `testListProductsReturnsEmptyInitially` | GET /products → 200 空配列 |
| `testCreateProductRequiresApiKey` | POST /products（キーなし）→ 401 |
| `testCreateProductWithApiKey` | POST /products（キーあり）→ 201 |
| `testCreateProductValidationRejectsInvalidFields` | バリデーション → 422 |
| `testListProductsShowsCreatedProducts` | 複数作成後の一覧確認 |
| `testBulkCreateProductsWithApiKey` | POST /products/bulk → 201, created=10 |
| `testBulkCreateLargePayloadWithinDefaultLimit` | 500件 ≈ 30KB → 201, created=500 |
| `testCreateOrderExercisesTransaction` | POST /orders → トランザクション実行、total 計算確認 |
| `testGetOrderById` | GET /orders/{id} → 200、注文詳細 |
| `testCreateOrderDecrementsStock` | 注文後の在庫デクリメント確認 |
| `testCreateOrderWithInsufficientStockReturns409` | 在庫不足 → 409 |
| `testCreateOrderRollsBackTransactionOnInsufficientStock` | 途中失敗でロールバック確認 |
| `testCreateOrderWithMissingProductReturns404` | 存在しない商品 → 404 |
| `testGetOrderByIdReturns404ForMissingOrder` | 存在しない注文 → 404 |
| `testCreateOrderValidationRejectsEmptyItems` | 空 items → 422 |

**合計**: 15/15 通過

---

## 総評

v1.5.1 でのトランザクションとバルク操作の基本的な流れは機能する。
ただし F-1（トランザクション内の DI 制約）は設計上の根本的な摩擦であり、
ユースケース層がインフラ詳細を知る必要が生じている。
F-2（protectedPaths デフォルト競合）は試行錯誤なしには発見しにくい。

次のアクション候補:
1. F-1 → `RepositoryFactory` 抽象または `transactional()` のコネクション共有設計を検討（新 Issue）
2. F-2 → `machineApiKeyProtectedPaths` デフォルト変更または PHPDoc 改善（新 Issue）
3. F-3 → `RuntimeApplicationFactory` に `requestMaxBodyBytes` パラメータ追加（新 Issue）
