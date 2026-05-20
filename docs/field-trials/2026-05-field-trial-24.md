# Field Trial 24 — habitlog: SecurityHeadersMiddleware・CorsMiddleware・RequestSizeLimitMiddleware 実地検証

## Date

2026-05-20

## Baseline

- NENE2 v1.5.7（`hideyukimori/nene2: ^1.5`、Packagist から取得）
- PHP 8.4
- プロジェクト: **habitlog** — 習慣トラッキング API
- エンティティ: `Habit`（name, frequency, description）/ `Completion`（habit_id, completed_on, note）
- DB: SQLite（テストごとに一時ファイル生成・削除）
- テスト: PHPUnit 24/24・PHPStan level 8・PHP-CS-Fixer 全通過

## Goal

v1.5.7 で未検証だったミドルウェア動作の実地検証:

- `SecurityHeadersMiddleware` — 全レスポンス（200 / 404 / 422 / 500 など）にセキュリティヘッダーが付くか
- `CorsMiddleware` — `allowedOrigins` の制御、拒否挙動、CORS なし設定との対比
- `RequestSizeLimitMiddleware` — `requestMaxBodyBytes` の効果
- `RequestIdMiddleware` — `X-Request-Id` ヘッダーのリクエストごとのユニーク性

---

## 実装ログ

1. `/home/xi/docker/NENE2-FT/habitlog/` に新規 PHP プロジェクト作成
2. `composer require hideyukimori/nene2:^1.5` — v1.5.7 インストール成功
3. SQLite スキーマ（habits / completions）と `SqliteHabitRepository` を実装
4. CORS 設定あり・なし 2 インスタンスでミドルウェア動作をテスト
5. テスト実行 → 1 件失敗（F-1: CORS preflight が 200 ではなく 204 を返す）
6. 修正後 24/24 通過。PHPStan level 8: 0 エラー。PHP-CS-Fixer: 0 件。

---

## 摩擦記録

### F-1（低）: CORS preflight の応答コードが 204

**状況**: `CorsMiddleware` は OPTIONS プリフライトに `204 No Content` を返す。
RFC 9110 では 204 が許容されているが、一部の古いブラウザや Proxy は 200 を期待する場合がある。
ドキュメントに記載がなく、初見で 200 を期待してテストを書いたため 1 件失敗した。

```php
// 期待: 200 → 実際: 204
$res = $this->appWithCors->handle(optionsRequest);
$this->assertSame(200, $res->getStatusCode()); // FAIL
```

**期待する解決策**: `CorsMiddleware` PHPDoc または howto に「プリフライトは 204 を返す」と明記する。

---

### F-2（なし）: SecurityHeadersMiddleware は設定なしで全レスポンスに付く

検証目的の確認。`RuntimeApplicationFactory` が常に `SecurityHeadersMiddleware` をパイプラインに追加するため、追加設定なしで全レスポンスにヘッダーが付く。これは期待どおり。

---

## ミドルウェア検証結果

### SecurityHeadersMiddleware

| 検証内容 | 結果 |
|---|---|
| 200 OK に CSP ヘッダーが付く | ✓ `default-src 'self'` |
| 200 OK に X-Content-Type-Options が付く | ✓ `nosniff` |
| 200 OK に X-Frame-Options が付く | ✓ `SAMEORIGIN` |
| 200 OK に Referrer-Policy が付く | ✓ `no-referrer-when-downgrade` |
| 404 エラーにもヘッダーが付く | ✓ |
| 422 バリデーションエラーにもヘッダーが付く | ✓ |

### CorsMiddleware

| 検証内容 | 結果 |
|---|---|
| `allowedOrigins: []`（デフォルト）で CORS ヘッダーなし | ✓ |
| 許可オリジンのリクエストに `Access-Control-Allow-Origin` が付く | ✓ |
| 不許可オリジンには CORS ヘッダーなし | ✓ |
| OPTIONS プリフライトへの応答 | ✓ 204（注: 200 ではない） |
| `['*']` を渡すと `InvalidArgumentException` | ✓（文書化済み） |

### RequestSizeLimitMiddleware

| 検証内容 | 結果 |
|---|---|
| 制限超過リクエストが 413 を返す | ✓ |
| 413 レスポンスが Problem Details 形式 | ✓ |
| デフォルト制限（1MiB）では通常リクエストが通る | ✓ |

### RequestIdMiddleware

| 検証内容 | 結果 |
|---|---|
| `X-Request-Id` ヘッダーが全レスポンスに付く | ✓ |
| リクエストごとに異なる ID が生成される | ✓ |

---

## テストカバレッジ

| テスト | 検証内容 |
|---|---|
| `testSecurityHeadersPresentOnEveryResponse` | CSP・nosniff・SAMEORIGIN・Referrer-Policy |
| `testSecurityHeadersPresentOnErrorResponse` | 404 でも SecurityHeaders が付く |
| `testSecurityHeadersPresentOnValidationError` | 422 でも SecurityHeaders が付く |
| `testRequestIdHeaderPresentInResponse` | X-Request-Id の存在確認 |
| `testRequestIdIsUniquePerRequest` | リクエストごとに異なる ID |
| `testCorsNotAddedWhenAllowedOriginsEmpty` | CORS ヘッダーが付かない |
| `testCorsAllowedOriginReturnsHeader` | 許可オリジンに CORS ヘッダーが付く |
| `testCorsDisallowedOriginGetsNoHeader` | 不許可オリジンに CORS ヘッダーが付かない |
| `testCorsPreflightOptionsReturns204` | OPTIONS が 204 を返す |
| `testRequestSizeLimitRejects413` | サイズ超過で 413 |
| `testDefaultSizeLimitAllowsNormalRequests` | 通常リクエストが通る |
| `testListHabitsReturnsEmpty` | GET /habits → 空リスト |
| `testCreateHabitReturns201` | POST /habits → 201 |
| `testCreateHabitRejectsMissingName` | 422・`required` |
| `testCreateHabitRejectsInvalidFrequency` | 422・`invalid_value` |
| `testFilterByFrequency` | ?frequency= フィルタ |
| `testGetHabitReturns404ForMissing` | 404 |
| `testDeleteHabit` | DELETE → 200 |
| `testCompleteHabitReturns201` | POST /habits/{id}/completions → 201 |
| `testDuplicateCompletionReturns409` | 重複完了 → 409 |
| `testListCompletions` | GET completions |
| `testStreakCountsConsecutiveDays` | 3 日連続 → streak=3 |
| `testStreakBreaksOnGap` | 中抜け → streak=1 |
| `testStreakIsZeroWithNoCompletions` | 未完了 → streak=0 |

**合計**: 24/24 通過

---

## 総評

`SecurityHeadersMiddleware`・`CorsMiddleware`・`RequestSizeLimitMiddleware`・`RequestIdMiddleware` は NENE2 v1.5.7 で正常に動作した。

主な摩擦は F-1（CORS preflight が 204 を返すが、ドキュメントに明記なし）のみ。影響は低い。

次のアクション候補:
1. F-1 → `CorsMiddleware` PHPDoc に「preflight は 204 を返す」と明記
