# Field Trial 12-C — shoplog: Multi-Auth Ergonomics 実地検証

## Date

2026-05-19

## Baseline

- NENE2 v1.4.1（`hideyukimori/nene2: ^1.4`）
- PHP 8.4
- プロジェクト: **shoplog** — 商品カタログ JSON API
- エンティティ: `Product` / `Category` / `Favorite`（3層アクセスモデル）
- テスト: PHPUnit（29テスト）・PHPStan level 8・PHP-CS-Fixer
- DB: SQLite（ローカル）

## Goal

3層アクセスモデル（公開 / JWT Bearer / API キー）を単一アプリで実現し、設計の摩擦を記録する。

| 層 | 認証方式 | 操作 |
|---|---|---|
| 公開 | なし | 商品・カテゴリ一覧・詳細（読み取り専用） |
| ユーザー | JWT Bearer | お気に入り登録・削除・一覧（`NENE2_LOCAL_JWT_SECRET`） |
| 管理者 | API キー | 商品・カテゴリの CRUD（`NENE2_MACHINE_API_KEY`） |

---

## Findings

### F-1: `RuntimeApplicationFactory` が受け取れる `$authMiddleware` は 1 つのみ [高]

Bearer と API キーを同時に適用したい場合、単一の引数では両方を表現できない。

**解決**: `MultiAuthMiddleware` を新設し、パスプレフィックスで振り分けることで合成した。
```php
final readonly class MultiAuthMiddleware implements MiddlewareInterface {
    public function process(...): ResponseInterface {
        if (str_starts_with($path, '/me/')) {
            return $this->bearerMiddleware->process($request, $handler);
        }
        return $this->writeApiKeyMiddleware->process($request, $handler);
    }
}
```

**提案**: `RuntimeApplicationFactory` に `$authMiddlewares: list<MiddlewareInterface>` を受け取れるようにするか、フレームワークで `CompositeAuthMiddleware` を提供する。

---

### F-2: `BearerTokenMiddleware::$protectedPaths` が動的パスに非対応 [高]

`/me/favorites/{productId}` のような動的パスは完全一致リストにマッチしない。

**解決**: `MultiAuthMiddleware` が `/me/` プレフィックスのルーティングを担当し、内部では `protectedPaths: []`（全パス保護）で Bearer を設定。

**提案**: `$protectedPaths` にプレフィックスマッチング（または `$protectedPathPrefixes` パラメータ）を追加する。

---

### F-3: `ApiKeyAuthenticationMiddleware` がパスパターンと HTTP メソッドの組み合わせに非対応 [高]

GET /products（公開）と POST /products（管理者）が同じパスで HTTP メソッドが違うため、パスのみでの保護では GET も 401 になる。

**解決**: `WriteApiKeyMiddleware` をカスタム実装。HTTP メソッドで判断（POST/PUT/DELETE → 認証必須、GET → スルー）し、`/auth/*` と `/me/*` プレフィックスは除外。

**提案**: `ApiKeyAuthenticationMiddleware` にメソッドフィルタリングと動的パスマッチングを追加する。

---

### F-4: `BearerTokenMiddleware` の `excludedPaths` と `RuntimeApplicationFactory` の組み合わせが難しい [中]

`BearerTokenMiddleware` は `$excludedPaths` を持つが、`RuntimeApplicationFactory` 経由では全パス保護モードで注入する際に `/auth/*` も保護されてしまう問題がある。

**解決**: FT12-C では `MultiAuthMiddleware` が `/me/` 以外を `WriteApiKeyMiddleware` に渡すため、Bearer は `/me/*` にしか適用されず、自然に回避された。

---

### F-5: `LocalBearerTokenVerifier` が `@internal` だが直接使用が最もシンプル [低]

`TokenIssuerInterface` は公開 API として存在し、`LocalBearerTokenVerifier` はそれを実装している。`@internal` タグはあるが、型で受け取れば機能上の問題はない。

**提案**: `LocalBearerTokenVerifier` の `@internal` タグを削除するか、公開クラス `LocalTokenVerifier` を用意する。

---

### F-6: `composer analyse` がデフォルトのメモリ制限で OOM になる [低]

並列ワーカーが 128MB を超えて OOM でクラッシュした。

**解決**: `composer.json` の `analyse` スクリプトに `php -d memory_limit=512M` を追加。

**提案**: NENE2 の composer.json テンプレートに `memory_limit` の設定を記載する。

---

## Multi-Auth 設計パターン

```
Request
  │
  ├── MultiAuthMiddleware
  │     ├── /me/* → BearerTokenMiddleware（全パス保護モード）
  │     └── other → WriteApiKeyMiddleware
  │           ├── GET/HEAD → pass through（公開）
  │           ├── /auth/* → pass through（公開）
  │           ├── /me/* → pass through（Bearer が処理）
  │           └── POST/PUT/DELETE → X-NENE2-API-Key 必須
  └── Router → handler
```

---

## Test Results

```
PHPUnit:         29/29 tests
PHPStan level 8: No errors
PHP-CS-Fixer:    0 files to fix
```

---

## Friction Summary

| # | 内容 | 深刻度 | 種別 |
|---|---|---|---|
| F-1 | `RuntimeApplicationFactory` が 1 つの認証ミドルウェアしか受け取れない | 高 | 拡張性 |
| F-2 | `BearerTokenMiddleware` が動的パスに非対応 | 高 | API 設計 |
| F-3 | `ApiKeyAuthenticationMiddleware` がメソッド単位での保護に非対応 | 高 | API 設計 |
| F-4 | `BearerTokenMiddleware::excludedPaths` と `RuntimeApplicationFactory` の組み合わせが難しい | 中 | API 設計 |
| F-5 | `LocalBearerTokenVerifier` が `@internal` だが実質的に直接使う必要がある | 低 | API 設計 |
| F-6 | PHPStan の OOM（`memory_limit` 設定が必要） | 低 | ドキュメント |

---

## Overall Impression

3層アクセスモデル自体は `MultiAuthMiddleware` パターンで実現できたが、フレームワークにこの合成パターンのサポートがないため毎回自作が必要。FT12-B (knowledgelog) でも同じ問題が出ており、`CompositeAuthMiddleware` または `$authMiddlewares` 複数受け取りが重要な改善ポイント。
