# Field Trial 22 — votelog: ThrottleMiddleware・HealthCheckInterface・QueryStringParser 実地検証

## Date

2026-05-20

## Baseline

- NENE2 v1.5.5（`hideyukimori/nene2: ^1.5`、Packagist から取得）
- PHP 8.4
- プロジェクト: **votelog** — 投票/ポーリング管理 API
- エンティティ: `Poll`（question, is_active, options[]）/ `PollOption`（label, position, vote_count）/ `Vote`（poll_id, option_id, voter_key）
- DB: SQLite（テストごとにファイル新規生成・削除）
- テスト: PHPUnit 18/18・PHPStan level 8・PHP-CS-Fixer 全通過

## Goal

v1.5.5 で未検証だった公開 API の実地検証:

- `ThrottleMiddleware` + `RateLimitStorageInterface` の組み合わせ
- `InMemoryRateLimitStorage`（`@internal` 指定あり）をテストで使用
- `HealthCheckInterface` のカスタム実装
- `QueryStringParser::bool()`（v1.5.5 新規追加）の実用確認

---

## 実装ログ

1. `/home/xi/docker/NENE2-FT/votelog/` に新規 PHP プロジェクト作成
2. `composer require hideyukimori/nene2:^1.5` — v1.5.5 インストール成功
3. SQLite スキーマ（polls / poll_options / votes）と `SqlitePollRepository` を実装
4. `PollRouteRegistrar` に GET・POST・GET/{id}・POST/{id}/vote を実装
5. `DatabaseHealthCheck`（`HealthCheckInterface` 実装）を作成
6. `ThrottleMiddleware` を `RuntimeApplicationFactory::$throttleMiddleware` に接続
7. テスト実行 → 1 件失敗（F-4: PDOException が DatabaseConnectionException にラップされる）
8. 修正後 18/18 通過。PHPStan level 8: 0 エラー。PHP-CS-Fixer: 0 件。

---

## 摩擦記録

### F-1（低）: QueryStringParser::bool() — `?is_active=` の挙動が直感的

**状況**: `QueryStringParser::bool($request, 'is_active')` は:
- キー不在 → `null`（フィルタなし）
- `?is_active=true` → `true`
- `?is_active=false` → `false`
- `?is_active=` → `null`（空文字は不在扱い）

の挙動を持つ。摩擦ではなく **期待どおり動作した**。
F-2 で発見した boolean クエリパラメータ問題が v1.5.5 で解消されていることを確認。

---

### F-2（中）: `InMemoryRateLimitStorage` が `@internal` なのにテストで必須

**状況**: テストで `ThrottleMiddleware` を使う場合、rate limit を実際に消費せず
テストを独立させるには `RateLimitStorageInterface` の実装が必要。

`InMemoryRateLimitStorage` はまさにそのための実装だが、`@internal` アノテーションが付いており
公開 API ではない。結果として consumer は:

1. `@internal` のクラスを使う（PHPStan 警告が出る場合がある）
2. 自分で `RateLimitStorageInterface` を実装する

```php
// テストで @internal な InMemoryRateLimitStorage を使う
$storage = new InMemoryRateLimitStorage();
$throttle = new ThrottleMiddleware($probs, $storage, limit: 1, windowSeconds: 60);
```

**期待する解決策**:
- `InMemoryRateLimitStorage` を公開 API に昇格させる（`@internal` を外す）
- または howto にテスト用ストレージの作り方を記載する

---

### F-3（低）: HealthCheckInterface のレスポンス形状が未ドキュメント

**状況**: `GET /health` のレスポンス形状（`{status, checks: {name: "ok"|"error"}}`）が
`HealthCheckInterface` の PHPDoc や howto に記載されていない。
実装してテストを書くまで `checks.database` のキー名が `name()` の戻り値であることに
気づかなかった。

```json
{
    "status": "ok",
    "checks": {
        "database": "ok"
    }
}
```

**期待する解決策**: `docs/howto/add-health-check.md` にレスポンス形状の例を追記する。

---

### F-4（中）: `execute()` の UNIQUE 制約例外が `DatabaseConnectionException` にラップされる

**状況**: `PdoDatabaseQueryExecutor::execute()` は内部で `PDOException` を
`DatabaseConnectionException` に変換して投げる。

UNIQUE 制約違反を検出したい consumer は `PDOException` をキャッチできず、
`DatabaseConnectionException` をキャッチして `getPrevious()` でラップを剥がす
必要がある。

```php
// 誤り: PDOException はキャッチできない
} catch (\PDOException $e) {
    if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
        throw new DuplicateVoteException();
    }
}

// 正しい: DatabaseConnectionException をキャッチして previous を検査
} catch (DatabaseConnectionException $e) {
    $previous = $e->getPrevious();
    if ($previous !== null && str_contains($previous->getMessage(), 'UNIQUE constraint failed')) {
        throw new DuplicateVoteException();
    }
    throw $e;
}
```

**期待する解決策**: `add-database-endpoint.md` または新 howto に UNIQUE 制約ハンドリング
パターンを追記する。

---

## ThrottleMiddleware 検証

| 検証内容 | 結果 |
|---|---|
| `RuntimeApplicationFactory::$throttleMiddleware` に直接渡せる | ✓ |
| `X-RateLimit-Limit` / `Remaining` / `Reset` ヘッダーが付く | ✓ |
| `limit=1` で 2 件目が 429 を返す | ✓ |
| `Retry-After` ヘッダーが 429 に含まれる | 未検証（Problem Details レスポンスに含まれる） |
| `keyExtractor` カスタマイズ | 未検証（デフォルトの REMOTE_ADDR で動作確認） |

---

## HealthCheckInterface 検証

```php
// 実装
final readonly class DatabaseHealthCheck implements HealthCheckInterface
{
    public function name(): string { return 'database'; }
    public function check(): HealthStatus
    {
        try {
            $this->executor->fetchOne('SELECT 1 AS ping');
            return HealthStatus::Ok;
        } catch (\Throwable) {
            return HealthStatus::Error;
        }
    }
}

// RuntimeApplicationFactory
healthChecks: [new DatabaseHealthCheck($executor)],
```

`name()` の戻り値が `/health` レスポンスの `checks` のキーになることを確認。

---

## テストカバレッジ

| テスト | 検証内容 |
|---|---|
| `testListPollsReturnsEmptyInitially` | GET /polls → 空・ページネーションメタ |
| `testCreatePollReturns201` | POST /polls → 201 |
| `testCreatePollRejectsMissingQuestion` | 422 |
| `testCreatePollRejectsFewerThanTwoOptions` | 422（選択肢 1 件）|
| `testGetPollById` | GET /polls/{id} → 200 |
| `testGetNonExistentPollReturns404` | 404 |
| `testFilterByIsActiveTrueReturnsActivePolls` | ?is_active=true フィルタ |
| `testFilterByIsActiveFalseReturnsEmpty` | ?is_active=false フィルタ |
| `testFilterAbsentIsActiveReturnsAll` | フィルタなし → 全件 |
| `testVoteUpdatesVoteCount` | 投票 → vote_count 増加 |
| `testDuplicateVoteReturns409` | 同一 IP 二重投票 → 409 |
| `testDifferentVotersCanVoteOnSamePoll` | 別 IP は投票可能 |
| `testVoteForInvalidOptionReturns422` | 存在しない optionId → 422 |
| `testVoteOnNonExistentPollReturns404` | 存在しない poll → 404 |
| `testRateLimitHeadersPresentOnSuccess` | X-RateLimit-* ヘッダー確認 |
| `testThrottleReturns429WhenLimitExceeded` | limit=1 → 2 件目 429 |
| `testHealthEndpointReturns200` | GET /health → status:ok, checks.database |
| `testPaginationLimitAndOffset` | limit=2&offset=0 → 2 件 / total=5 |

**合計**: 18/18 通過

---

## 総評

`ThrottleMiddleware`・`HealthCheckInterface`・`QueryStringParser::bool()` は
NENE2 v1.5.5 で正常に動作した。

主な摩擦は `InMemoryRateLimitStorage` の `@internal` 問題（F-2）と
`execute()` の例外ラップ（F-4）。F-2 は消費者が必ず遭遇するパターンで影響が大きい。

次のアクション候補:
1. F-2 → `InMemoryRateLimitStorage` を公開 API に昇格（`@internal` 削除）
2. F-3 → `add-health-check.md` にレスポンス形状を追記
3. F-4 → `add-database-endpoint.md` に UNIQUE 制約ハンドリング例を追記
