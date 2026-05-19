# Field Trial 17 — quotelog: ApiKeyAuthenticationMiddleware method filter 実地検証

## Date

2026-05-20

## Baseline

- NENE2 v1.5.0（`hideyukimori/nene2: ^1.5`、Packagist から取得）
- PHP 8.4
- プロジェクト: **quotelog** — 名言・引用 API
- エンティティ: `Quote`（text, author, source, created_at）
- テスト: PHPUnit 12/12・PHPStan level 8・PHP-CS-Fixer（`--allow-risky=yes`）
- DB: SQLite（インメモリ、テスト用）

## Goal

v1.5.0 で追加された `ApiKeyAuthenticationMiddleware::$protectedMethods`（メソッドフィルタ）と
`$protectedPathPrefixes`（プレフィックス allowlist）を実アプリで初めて使い、
Packagist v1.5.0 の範囲で動作するかを検証する。

アクセスモデル:
- `GET /quotes`, `GET /quotes/{id}` → パブリック（認証不要）
- `POST /quotes`, `PUT /quotes/{id}`, `DELETE /quotes/{id}` → API key 必須

---

## 実装ログ

1. `composer require hideyukimori/nene2:^1.5` — Packagist に v1.5.0 が既に存在し、path リポジトリ不要。
2. Quote ドメイン（`Quote`, `QuoteRepositoryInterface`, `SqliteQuoteRepository`, `QuoteRouteRegistrar`, `QuoteNotFoundExceptionHandler`）を実装。
3. テスト 12 本を作成・全通過（12/12）。
4. PHPStan level 8 で 1 エラー → `fetchAll()` の戻り値型 PHPDoc を追加して解消。
5. PHP-CS-Fixer で 1 ファイル整形（`QuoteRouteRegistrar.php` のインデント）。
6. `--allow-risky=yes` が必要（F-2 参照）。

---

## 摩擦記録

### F-1（高）: `RuntimeApplicationFactory` に `$protectedMethods`・`$protectedPathPrefixes` が未露出

**状況**: v1.5.0 の `ApiKeyAuthenticationMiddleware` には `$protectedMethods` と `$protectedPathPrefixes` が追加されている。
しかし `RuntimeApplicationFactory`（v1.5.0）はこれらを引数として露出していない。
factory のコンストラクタは `$machineApiKey` のみで、内部的に `['/machine/health']` をハードコードした `ApiKeyAuthenticationMiddleware` を生成する。

**期待**: `RuntimeApplicationFactory` に `machineApiKeyProtectedMethods` と `machineApiKeyProtectedPathPrefixes` パラメータがあれば、手動構築なしに method filter を利用できる。

**回避策**: `ApiKeyAuthenticationMiddleware` を手動で構築し、`RuntimeApplicationFactory::$authMiddleware` として渡した。
`$authMiddleware` はセマンティック的には Bearer token 用だが、任意の `MiddlewareInterface` を受け取るため技術的には動作する。

```php
$apiKeyMiddleware = new ApiKeyAuthenticationMiddleware(
    $problems,
    $machineApiKey,
    protectedPathPrefixes: ['/quotes'],
    protectedMethods:      ['POST', 'PUT', 'DELETE'],
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    authMiddleware: $apiKeyMiddleware,  // Bearer 用パラメータを流用
    ...
))->create();
```

**影響**: コードを読んだ人が「なぜ API key ミドルウェアを `authMiddleware` に渡しているのか？」と混乱する。
命名の不一致がドキュメントなしでは伝わらない。

**注**: `RuntimeApplicationFactory` には dev HEAD で `$machineApiKeyProtectedPaths`・`$machineApiKeyExcludedPaths`・`$allowedOrigins` が追加されているが、
`$machineApiKeyProtectedMethods`・`$machineApiKeyProtectedPathPrefixes` はいずれのバージョンにも未収録。

---

### F-2（低）: PHP-CS-Fixer の `declare_strict_types` ルールに `--allow-risky=yes` が必要

**状況**: `declare_strict_types` fixer は "risky" 扱いで、`--allow-risky=yes` フラグなしには動作しない。
NENE2 の `.php-cs-fixer.php` はコア側でこの問題を吸収しているが、consumer project が独自 CS 設定を持つ場合は自分で設定が必要。

**回避策**: `.php-cs-fixer.php` に `->setRiskyAllowed(true)` を追加、composer scripts に `--allow-risky=yes` を追加した。

**ドキュメントへの示唆**: `add-custom-route.md` などの "getting started" 系 howto に CS Fixer の risky 設定に関する一言を追加すると良い。

---

## 検証結果

| 項目 | 結果 |
|---|---|
| PHPUnit | 12/12 ✓ |
| PHPStan level 8 | OK ✓ |
| PHP-CS-Fixer | OK ✓ |
| `$protectedMethods` 動作 | GET はパブリック、POST/PUT/DELETE は API key 必須 ✓ |
| `$protectedPathPrefixes` 動作 | `/quotes` プレフィックスで保護 ✓ |
| Packagist v1.5.0 から取得 | path リポジトリ不要 ✓ |

---

## フォローアップ候補

| ID | 内容 | 優先度 |
|---|---|---|
| F-1 | `RuntimeApplicationFactory` に `machineApiKeyProtectedMethods`・`machineApiKeyProtectedPathPrefixes` を追加 | 高 |
| F-2 | howto 系ドキュメントに PHP-CS-Fixer risky 設定の説明を追加 | 低 |
