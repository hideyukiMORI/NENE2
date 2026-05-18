# ヘルスチェックを追加する

このガイドでは、`HealthCheckInterface` を使って `GET /health` エンドポイントに依存関係のヘルスチェックを追加する方法を説明します。

**前提条件**: 動作する NENE2 アプリケーションがあること。まだの場合は [チュートリアル](../tutorial/first-api.md) から始めてください。

---

## ヘルスチェックの動作

`GET /health` は常にベースペイロードを返します:

```json
{ "service": "NENE2", "status": "ok", "timestamp": "2026-05-18T12:00:00+00:00" }
```

`HealthCheckInterface` 実装を登録すると、エンドポイントに `checks` マップが追加されます:

- すべてのチェックが成功 → `200 OK`、`"status": "ok"`、各チェックが `"ok"` を表示
- いずれかのチェックが失敗 → `503 Service Unavailable`、`"status": "degraded"`、失敗したチェックが `"error"` を表示

---

## クイックスタート

`HealthCheckInterface` を実装し、`RuntimeApplicationFactory` に渡します:

```php
use Nene2\Http\HealthCheckInterface;
use Nene2\Http\RuntimeApplicationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;

final class CacheHealthCheck implements HealthCheckInterface
{
    public function name(): string { return 'cache'; }

    public function check(): bool
    {
        // true = 正常、false = 異常
        return $this->ping();
    }

    private function ping(): bool { /* ... */ return true; }
}

$psr17 = new Psr17Factory();

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [new CacheHealthCheck()],
))->create();
```

**正常時レスポンス** (`200 OK`):

```json
{
  "service": "NENE2",
  "status": "ok",
  "timestamp": "2026-05-18T12:00:00+00:00",
  "checks": { "cache": "ok" }
}
```

**異常時レスポンス** (`503 Service Unavailable`):

```json
{
  "service": "NENE2",
  "status": "degraded",
  "timestamp": "2026-05-18T12:00:00+00:00",
  "checks": { "cache": "error" }
}
```

---

## DatabaseHealthCheck 参照実装を使う

`src/Example/Health/DatabaseHealthCheck` は PDO データベース接続確認のための既製チェックです。`src/Example/` の一部なので、自分のプロジェクトにコピーして適用してください。

```php
use Nene2\Example\Health\DatabaseHealthCheck;

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [new DatabaseHealthCheck($pdoConnection)],
))->create();
```

このチェックは `SELECT 1` を実行し、成功すれば `true`、例外があれば `false` を返します。

> **注意**: `DatabaseHealthCheck` は `src/Example/` にあり、参照実装です（安定 API 保証外）。
> アプリケーションにコピーして用途に合わせて適応させてください。

---

## 複数のヘルスチェック

必要な数だけチェックを渡せます。各チェックは独立して実行され、いずれかが失敗すると全体のステータスが degraded になります。

```php
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [
        new DatabaseHealthCheck($pdoConnection),
        new CacheHealthCheck($redis),
        new ExternalApiHealthCheck($httpClient),
    ],
))->create();
```

データベースは正常だがキャッシュが異常な場合のレスポンス:

```json
{
  "service": "NENE2",
  "status": "degraded",
  "timestamp": "2026-05-18T12:00:00+00:00",
  "checks": {
    "database": "ok",
    "cache": "error",
    "external-api": "ok"
  }
}
```

---

## チェック内の例外処理

`check()` メソッドが例外をスローした場合、`RuntimeApplicationFactory` は `false` を返した場合と同様に扱います。ステータスは `"degraded"` になり、チェックは `"error"` を表示します。`check()` 内で例外をキャッチする必要はありません。

---

## 次のステップ

完全な `/health` レスポンスのスキーマは [HTTP エンドポイント](../reference/http-endpoints.md) を参照するか、
リクエスト保護として [レート制限を追加する](./add-rate-limiting.md) を参照してください。
