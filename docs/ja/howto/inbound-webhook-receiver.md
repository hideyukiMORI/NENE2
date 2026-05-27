# インバウンド Webhook レシーバーの追加方法

複数の外部サービスから Webhook を受信し、ソースごとに HMAC 署名を検証し、冪等性付きでイベントを保存します。

## スキーマ

```sql
CREATE TABLE webhook_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE, secret TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL
);
CREATE TABLE inbound_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL REFERENCES webhook_sources(id),
    event_id TEXT NOT NULL, event_type TEXT NOT NULL,
    payload TEXT NOT NULL, processed_at TEXT NOT NULL,
    UNIQUE(source_id, event_id)
);
```

## ルート

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/sources` | Webhook ソースを登録する |
| `POST` | `/sources/{id}/receive` | Webhook を受信する |
| `GET` | `/sources/{id}/events` | 受信したイベントを一覧表示する |
| `GET` | `/events/{id}` | 特定のイベントを取得する |

## HMAC-SHA256 署名バリデーション

各ソースには独自の HMAC シークレットがあります。レスポンスには絶対に公開しないでください。

```php
private function verifySignature(string $body, string $header, string $secret): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $expected = hash_hmac('sha256', $body, $secret);
    return hash_equals($expected, substr($header, 7)); // タイミングセーフ
}
```

呼び出し順序: **先に署名を検証**し、次に冪等性チェック、その後に保存:

```php
if (!$this->verifySignature($rawBody, $sigHeader, $source['secret'])) {
    return $this->json->create(['error' => 'Invalid signature'], 401);
}
// ... 冪等性チェック ...
$this->repo->storeEvent($sourceId, $eventId, $eventType, $rawBody, $now);
```

## 冪等性（ソースごとの event_id）

```php
$existing = $this->repo->findEventBySourceAndEventId($sourceId, $eventId);
if ($existing !== null) {
    return $this->json->create(['status' => 'already_processed', 'id' => $existing['id']]);
}
```

`UNIQUE(source_id, event_id)` 制約は DB レベルのバックストップです。上記の PHP チェックは最初の重複で例外パスを避けます。

## シークレットを絶対に公開しない

```php
$source = $this->repo->findSource($id);
unset($source['secret']); // 返却前に除去
return $this->json->create($source, 201);
```

## 非アクティブソースのチェック

```php
if (!(bool) $source['active']) {
    return $this->json->create(['error' => 'Source is inactive'], 403);
}
```

## MySQL 注記

`UNIQUE KEY uq_source_event (source_id, event_id)` 制約は MySQL でも同様に機能します。インデックス付きテキストカラムには InnoDB のキー長制限内に収まるよう `VARCHAR(191)` を使用してください。

### MySQL 統合テストの実行

共有 FT MySQL コンテナを起動してください（ポート 3308、永続ボリューム）:

```bash
docker compose -f ../NENE2-FT/docker-compose.yml up -d mysql
```

次に環境変数で統合テストを実行してください:

```bash
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3308 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass \
  php8.4 vendor/bin/phpunit --filter Mysql
```

`MYSQL_HOST` なしでは MySQL テストは自動的にスキップされます（`markTestSkipped`）。

## セキュリティノート

- `hash_equals()` が署名比較のタイミング攻撃を防止します。
- 生の JSON ボディはそのまま保存されます。署名検証前に解析しないでください。
- 2 つの異なるソースからの同じ `event_id` は別々のレコードを作成します — UNIQUE 制約は `event_id` だけではなく `(source_id, event_id)` です。
